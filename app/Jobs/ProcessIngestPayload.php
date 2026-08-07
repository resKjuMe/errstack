<?php

namespace App\Jobs;

use App\Enums\DiscardReason;
use App\Enums\ProcessingState;
use App\Enums\QueueName;
use App\Models\IngestDiscard;
use App\Models\IngestPayload;
use App\Models\ProcessedEvent;
use App\Support\Ingest\Processing\ProcessingContext;
use App\Support\Ingest\Processing\ProcessingPipeline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Wertet eine angenommene Meldung im Hintergrund aus.
 *
 * Die Aufnahme legt nur ab und antwortet — die eigentliche Arbeit passiert
 * hier, außerhalb der Anfrage der überwachten Anwendung. Dieser Job ist der
 * Rahmen dafür: er entscheidet über Doppel, misst die Dauer, hält den Ausgang
 * fest und sorgt dafür, dass ein Fehler eine Wiederholung auslöst und keinen
 * Verlust. Was inhaltlich mit der Meldung geschieht, steht in den Schritten der
 * Kette ({@see ProcessingPipeline}) und nicht hier.
 *
 * Der Job ist wiederholbar, ohne Schaden anzurichten. Das ist keine Zugabe,
 * sondern Voraussetzung: eine Warteschlange darf denselben Job ein zweites Mal
 * ausliefern, wenn ein Arbeiter mitten im Lauf wegbricht.
 */
class ProcessIngestPayload implements ShouldQueue
{
    use Queueable;

    /**
     * Versuche, bevor die Meldung als endgültig gescheitert gilt.
     *
     * Fünf, weil die Fehler, gegen die Wiederholung überhaupt hilft, in Wellen
     * kommen: eine überlastete Datenbank, ein kurz nicht erreichbarer Dienst.
     * Wer nach dem dritten Versuch innerhalb einer Minute aufgibt, verliert
     * genau die Meldungen, die während der Störung eintrafen — also die
     * interessanten.
     */
    public int $tries = 5;

    /**
     * Ein Durchlauf, der länger braucht, hängt. Die Kette arbeitet auf Daten,
     * die bereits im Speicher liegen; zwei Minuten sind großzügig.
     */
    public int $timeout = 120;

    /**
     * Ist die Meldung nicht mehr da (Aufräumen alter Rohdaten, O2), ist der Job
     * gegenstandslos — und kein Fehlschlag, der die Fehlerablage füllen sollte.
     */
    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public IngestPayload $payload,
    ) {
        $this->onQueue(QueueName::Ingest->value);
    }

    /**
     * Zwei Arbeiter, die im selben Augenblick dieselbe Meldung bekommen, dürfen
     * nicht beide loslaufen.
     *
     * Der Zustand allein reicht dafür nicht: zwischen dem Blick darauf und dem
     * Festhalten des Ergebnisses liegt der ganze Durchlauf, und in dieser Zeit
     * sieht der zweite Arbeiter dieselbe wartende Meldung. Er käme bis zum Ende
     * durch — und alles, was die Kette unterwegs hochzählt, stünde doppelt da.
     *
     * Der Zweite wird deshalb zurückgestellt statt verworfen: ist der Erste
     * fertig, findet er ein Ergebnis vor und ist sofort wieder draußen; bricht
     * der Erste weg, ist die Meldung noch da und wird ausgewertet.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('ingest-payload:'.$this->payload->id))
                ->releaseAfter(10)
                ->expireAfter($this->timeout + 60),
        ];
    }

    /**
     * Wachsende Wartezeit zwischen den Versuchen, in Sekunden.
     *
     * Gleichbleibend kurze Abstände sind bei einer Störung das Falsche: sie
     * treffen den Dienst, der gerade wieder hochkommt, mit voller Wucht. Der
     * letzte Abstand ist deshalb lang genug, dass die Meldung auch einen
     * Neustart der Datenbank übersteht.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 120, 600];
    }

    public function handle(ProcessingPipeline $pipeline): void
    {
        $startedAt = hrtime(true);
        $payload = $this->payload;

        if (! $payload->processing_state->isOpen()) {
            // Schon durch. Das ist der Regelfall bei einer zweiten Auslieferung
            // desselben Jobs — und ein zweiter Lauf würde das Ergebnis des
            // ersten überschreiben, statt es zu bestätigen.
            return;
        }

        if (! $this->mayProcess($payload)) {
            $this->recordDiscard($payload, DiscardReason::Duplicate, $payload->type->value);

            $payload->finishProcessing(ProcessingState::Duplicate, self::elapsedMs($startedAt), $this->attempts());

            return;
        }

        $context = new ProcessingContext($payload);

        // Ausnahmen laufen hier bewusst durch: erst sie lösen die Wiederholung
        // aus. Was hier abgefangen würde, wäre eine Meldung, die als
        // ausgewertet gilt, ohne es zu sein.
        $pipeline->process($context);

        $duration = self::elapsedMs($startedAt);

        if ($context->isDropped()) {
            $reason = $context->dropReason();

            if ($reason !== null) {
                $this->recordDiscard($payload, $reason, $context->dropDetail());
            }

            $payload->finishProcessing(ProcessingState::Dropped, $duration, $this->attempts(), $reason?->value);

            return;
        }

        $payload->finishProcessing(ProcessingState::Processed, $duration, $this->attempts());
    }

    /**
     * Nach dem letzten vergeblichen Versuch.
     *
     * Zwei Dinge sind hier wichtiger als die Protokollzeile: die Meldung wird
     * als gescheitert gekennzeichnet, damit sie auffindbar und erneut
     * startbar ist — und der Anspruch auf ihre Nummer wird wieder freigegeben.
     * Sonst würde eine erneute Zustellung derselben Meldung als Doppel
     * abgetan, obwohl nie etwas ausgewertet wurde: aus einem Fehlschlag würde
     * ein dauerhafter Verlust.
     */
    public function failed(?Throwable $exception): void
    {
        // Frisch gelesen und nur, wenn noch nichts entschieden ist: gescheitert
        // sein kann auch ein Lauf, dessen Meldung längst ausgewertet war — etwa
        // eine zweite Auslieferung, die in eine Zeitüberschreitung läuft. Deren
        // Ergebnis hier zu überschreiben, hieße eine fertige Meldung nachher zu
        // einem Fehlschlag zu erklären.
        $payload = $this->payload->fresh();

        if ($payload === null || ! $payload->processing_state->isOpen()) {
            return;
        }

        ProcessedEvent::release($payload);

        $payload->finishProcessing(
            ProcessingState::Failed,
            $payload->duration_ms ?? 0,
            $this->attempts(),
            $exception?->getMessage(),
        );

        Log::error('Verarbeitung einer Meldung endgültig gescheitert.', [
            'meldung' => $payload->id,
            'projekt' => $payload->project_id,
            'ereignis' => $payload->event_id,
            'versuche' => $this->attempts(),
            'grund' => $exception?->getMessage(),
        ]);
    }

    /**
     * Darf diese Meldung ausgewertet werden — oder war ihre Nummer schon da?
     *
     * Nur Meldungen mit eigener Nummer werden überhaupt gefragt. Ein Anhang
     * trägt die Nummer der Meldung, zu der er gehört; ihn daran zu messen hieße,
     * jeden zweiten Screenshot desselben Fehlers wegzuwerfen.
     */
    private function mayProcess(IngestPayload $payload): bool
    {
        return ! $payload->isDeduplicable() || ProcessedEvent::claim($payload);
    }

    /**
     * Schreibt mit, was die Verarbeitung aussortiert hat.
     *
     * Ohne Schlüssel keine Zählung: die Statistik führt Verworfenes je Projekt
     * **und** Schlüssel, und ein zurückgezogener Schlüssel lässt an der Meldung
     * nur `null` zurück. Das betrifft ausschließlich Meldungen, deren Schlüssel
     * nach der Annahme gelöscht wurde — selten, und die Meldung selbst bleibt
     * davon unberührt.
     */
    private function recordDiscard(IngestPayload $payload, DiscardReason $reason, ?string $category): void
    {
        $key = $payload->key;

        if ($key === null) {
            return;
        }

        IngestDiscard::server($key, $reason, $category);
    }

    /**
     * Vergangene Zeit in Millisekunden. `hrtime()` statt der Uhrzeit, weil eine
     * Zeitumstellung sonst negative Dauern erzeugt.
     */
    private static function elapsedMs(int|float $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
