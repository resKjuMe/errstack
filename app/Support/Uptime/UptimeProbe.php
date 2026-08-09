<?php

namespace App\Support\Uptime;

use App\Enums\UptimeCheckOutcome;
use App\Models\UptimeMonitor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Throwable;

/**
 * Der Aufruf des Ziels und seine Bewertung.
 *
 * Die Klasse tut genau zwei Dinge, und beide sind Entscheidungen, keine
 * Mechanik: sie legt fest, **was als erreichbar gilt** (Statuscode und Inhalt),
 * und sie stellt eine gescheiterte Anfrage **noch einmal**, bevor sie sie
 * gelten lässt.
 *
 * **Die Wiederholung zur Bestätigung ist der Grund, warum eine Überwachung
 * brauchbar ist.** Ohne sie meldet sie jedes verlorene Paket, jede
 * Netzumschaltung und jeden Neustart der Gegenstelle als Ausfall — und nach der
 * dritten Nachricht dieser Art liest sie niemand mehr. Deshalb steht sie hier
 * und nicht als Wiederholung des Jobs: der Job wiederholt sich erst nach dem
 * nächsten Takt, und eine Minute später ist der Aussetzer längst vorbei, ohne
 * dass jemals jemand nachgesehen hätte.
 *
 * Was sie ausdrücklich **nicht** tut: sie speichert nichts und meldet nichts.
 * Beides gehört zum {@see UptimeRecorder} — dieselbe Trennung wie zwischen
 * Messung und Bewertung.
 */
final class UptimeProbe
{
    /**
     * So viel des Antwortrumpfs wird für die Inhaltsprüfung gelesen.
     *
     * Der erwartete Text steht praktisch immer im vorderen Teil einer Seite,
     * und ein Ziel, das zehn Megabyte ausliefert, soll den Arbeiter nicht
     * blockieren. Die Grenze wirkt nur auf die Suche, nicht auf die Übertragung
     * — das Herunterladen entscheidet die Zeitgrenze.
     */
    private const BODY_SCAN_LIMIT = 512 * 1024;

    /**
     * Prüft ein Ziel — mit Bestätigung, falls der erste Anlauf scheitert.
     *
     * Zurück kommt der **letzte** Anlauf, nicht der erste: gefragt ist, wie es
     * jetzt um das Ziel steht. Gelingt die Bestätigung, war der Aussetzer
     * folgenlos und die Prüfung gilt als erfolgreich — die Zahl der Anläufe
     * bleibt trotzdem am Ergebnis stehen, damit ein wackelndes Ziel im Verlauf
     * auffällt, bevor es ausfällt.
     */
    public function run(UptimeMonitor $monitor): ProbeResult
    {
        $attempts = 1 + max(0, $monitor->confirmation_retries);
        $result = $this->attempt($monitor);

        for ($attempt = 2; $attempt <= $attempts && $result->isFailure(); $attempt++) {
            // Der Abstand ist die halbe Miete: sofort noch einmal zu fragen
            // trifft dieselbe halb offene Verbindung und dieselbe überlastete
            // Gegenstelle.
            if ($monitor->confirmation_delay_seconds > 0) {
                Sleep::for($monitor->confirmation_delay_seconds)->seconds();
            }

            $result = $this->attempt($monitor)->withAttempts($attempt);
        }

        return $result;
    }

    /**
     * Ein einzelner Anlauf.
     */
    public function attempt(UptimeMonitor $monitor): ProbeResult
    {
        $startedAt = hrtime(true);

        try {
            $response = $this->send($monitor);
        } catch (ConnectionException $exception) {
            return $this->connectionFailure($exception->getMessage());
        } catch (Throwable $exception) {
            // Eine unerwartete Ausnahme — eine kaputte Adresse, ein
            // Zertifikatsfehler, den der Client anders verpackt. Sie darf die
            // Überwachung nicht anhalten: eine Prüfung, die mit einer Ausnahme
            // endet statt mit einem Ergebnis, ist ein blinder Fleck, und zwar
            // genau an der Stelle, an der jemand hinsehen wollte.
            return $this->connectionFailure($exception->getMessage());
        }

        $elapsedMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        return $this->evaluate($monitor, $response, $elapsedMs);
    }

    /**
     * Die Anfrage, wie der Monitor sie beschreibt.
     */
    private function send(UptimeMonitor $monitor): Response
    {
        $request = Http::timeout($monitor->timeout_seconds)
            ->connectTimeout($monitor->timeout_seconds)
            ->withHeaders($this->headers($monitor))
            // Ein Ziel, das sich nach dem Aufrufer richtet, soll dieselbe Seite
            // ausliefern wie einem Browser — und im Zugriffsprotokoll der
            // Gegenstelle soll erkennbar sein, wer hier alle paar Minuten
            // anklopft.
            ->withUserAgent(config('app.name').' Uptime-Monitor');

        $request = $monitor->follow_redirects ? $request : $request->withoutRedirecting();

        if (! $monitor->verify_tls) {
            $request = $request->withoutVerifying();
        }

        $request = $this->withBody($request, $monitor);

        return $request->send($monitor->method->value, $monitor->url);
    }

    /**
     * Die Nutzlast — nur bei den Verfahren, die eine tragen.
     *
     * Ein Rumpf an einem `GET` ist nicht verboten, aber praktisch immer ein
     * Versehen: er wird von den meisten Gegenstellen verworfen, und die
     * Überwachung prüfte dann etwas anderes, als eingestellt wurde.
     */
    private function withBody(PendingRequest $request, UptimeMonitor $monitor): PendingRequest
    {
        $body = $monitor->body;

        if ($body === null || $body === '' || ! $monitor->method->acceptsRequestBody()) {
            return $request;
        }

        return $request->withBody($body, $this->contentType($monitor));
    }

    /**
     * Der Inhaltstyp der Nutzlast: was in den Kopfzeilen steht, sonst JSON.
     *
     * JSON als Vorgabe, weil das die Nutzlast ist, die jemand einer
     * Prüf-Schnittstelle schickt. Wer etwas anderes meint, schreibt es als
     * Kopfzeile hin — dann gilt seine Angabe.
     */
    private function contentType(UptimeMonitor $monitor): string
    {
        foreach ($monitor->headers ?? [] as $header) {
            if (strcasecmp($header['name'], 'content-type') === 0) {
                return $header['value'];
            }
        }

        return 'application/json';
    }

    /**
     * Die eingestellten Kopfzeilen in der Form, die der Client erwartet.
     *
     * Gespeichert sind sie als Liste von Paaren (Reihenfolge, doppelte Namen);
     * hier werden gleichnamige zusammengefasst, weil genau das die Bedeutung
     * mehrerer gleichnamiger Kopfzeilen ist.
     *
     * @return array<string, list<string>>
     */
    private function headers(UptimeMonitor $monitor): array
    {
        $headers = [];

        foreach ($monitor->headers ?? [] as $header) {
            $name = trim($header['name']);

            // Eine Kopfzeile ohne Namen wirft der Client zurück. Die
            // Eingabeprüfung lässt sie gar nicht erst durch; hier steht nur, dass
            // ein von Hand geänderter Datensatz die Prüfung nicht anhält.
            if ($name === '') {
                continue;
            }

            $headers[$name][] = $header['value'];
        }

        return $headers;
    }

    /**
     * Bewertet die Antwort: erst der Statuscode, dann der Inhalt.
     *
     * Die Reihenfolge ist die der Aussagekraft. Ein `500` ist ein Ausfall, ganz
     * gleich was im Rumpf steht; erst wenn der Code stimmt, ist die Frage
     * interessant, ob dort auch die richtige Seite ausgeliefert wurde — und
     * genau dieser Fall, die Fehlerseite mit HTTP 200, ist der, den eine reine
     * Statusprüfung durchgehen lässt.
     */
    private function evaluate(UptimeMonitor $monitor, Response $response, int $elapsedMs): ProbeResult
    {
        $status = $response->status();

        if (! $monitor->statusExpectation()->matches($status)) {
            return new ProbeResult(
                outcome: UptimeCheckOutcome::StatusMismatch,
                httpStatus: $status,
                responseTimeMs: $elapsedMs,
                error: __('uptime.probe.status_mismatch', [
                    'status' => (string) $status,
                    'expected' => $monitor->expected_status_codes,
                ]),
            );
        }

        $expected = $monitor->expected_content;

        if ($expected !== null && $expected !== '' && ! $this->bodyContains($response, $expected)) {
            return new ProbeResult(
                outcome: UptimeCheckOutcome::ContentMismatch,
                httpStatus: $status,
                responseTimeMs: $elapsedMs,
                error: __('uptime.probe.content_mismatch', ['text' => $expected]),
            );
        }

        return new ProbeResult(
            outcome: UptimeCheckOutcome::Up,
            httpStatus: $status,
            responseTimeMs: $elapsedMs,
        );
    }

    private function bodyContains(Response $response, string $expected): bool
    {
        return str_contains(substr($response->body(), 0, self::BODY_SCAN_LIMIT), $expected);
    }

    /**
     * Kein Kontakt — und die Unterscheidung, ob es zu lange gedauert hat.
     *
     * Sie steckt im Text der Ausnahme, weil der Client beides in dieselbe
     * Ausnahme legt. Das ist nicht schön, aber die einzige Stelle, an der die
     * Auskunft überhaupt vorliegt — und der Unterschied zwischen „die
     * Gegenstelle ist weg" und „sie steht" ist die erste Frage, die jemand nach
     * so einer Meldung stellt.
     */
    private function connectionFailure(string $message): ProbeResult
    {
        $timedOut = Str::contains(Str::lower($message), ['timed out', 'timeout', 'zeitüberschreitung']);

        return new ProbeResult(
            outcome: $timedOut ? UptimeCheckOutcome::Timeout : UptimeCheckOutcome::ConnectionFailed,
            error: Str::limit($message, 500, ''),
        );
    }
}
