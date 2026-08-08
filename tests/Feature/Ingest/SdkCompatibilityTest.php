<?php

namespace Tests\Feature\Ingest;

use App\Enums\IngestType;
use App\Enums\ProcessingState;
use App\Models\Event;
use App\Models\IngestDiscard;
use App\Models\IngestPayload;
use App\Models\Issue;
use App\Models\Project;
use App\Models\ProjectKey;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Verträglichkeit mit den echten SDKs.
 *
 * Alle anderen Tests der Datenaufnahme schicken Meldungen, die wir selbst
 * geschrieben haben — und prüfen damit auch unsere Vorstellung davon, wie ein
 * SDK meldet. Dieser Test schickt Mitschnitte: Anfragen, wie sie
 * `sentry-php`, `@sentry/node`, `@sentry/browser` und `sentry-python`
 * tatsächlich abgesetzt haben, samt Kopfzeilen, Abfrageteil und Verpackung.
 *
 * Er ist deshalb die Absicherung dessen, was der Nachweis unter
 * `docs/compat/` einmal von Hand gezeigt hat: dass die Original-SDKs mit
 * diesem Klon reden, wenn man nur ihre DSN tauscht. Bricht eine Änderung das,
 * fällt es hier auf und nicht erst bei einer überwachten Anwendung, die
 * seitdem still ist.
 *
 * Angepasst wird an den Aufnahmen nur, was sich nicht anpassen lässt: die
 * Projektnummer im Pfad und der öffentliche Schlüssel, denn beide gehören zu
 * dem Projekt, das dieser Test gerade angelegt hat. Der Rumpf bleibt Byte für
 * Byte, wie er kam — der Klon liest die Zugangsdaten aus der Anfrage und nicht
 * aus der DSN im Envelope-Kopf, weshalb die Aufnahme-DSN darin stehen bleiben
 * darf.
 *
 * Neu aufnehmen: `docs/compat/README.md`.
 */
class SdkCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Der Schlüssel, mit dem aufgenommen wurde — offensichtlich keiner, den es
     * gibt, damit eine Aufnahme niemals einen echten Schlüssel enthält.
     */
    private const AUFNAHME_SCHLUESSEL = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const VORLAGEN = __DIR__.'/../../Fixtures/Compat';

    /**
     * Die Aufnahmen als Fälle für den Test — Name des Falls ist „sdk/art", denn
     * genau das steht bei einem Fehlschlag im Bericht und beantwortet die erste
     * Frage: welches SDK, welche Meldungsart.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function aufnahmen(): array
    {
        $faelle = [];

        foreach (self::verzeichnis() as $aufnahme) {
            $faelle[$aufnahme['sdk'].'/'.$aufnahme['art']] = [$aufnahme];
        }

        return $faelle;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function verzeichnis(): array
    {
        $inhalt = json_decode((string) file_get_contents(self::VORLAGEN.'/aufnahmen.json'), true);

        return is_array($inhalt) && is_array($inhalt['aufnahmen'] ?? null) ? $inhalt['aufnahmen'] : [];
    }

    /**
     * Der Nachweis selbst — je Aufnahme: die Anfrage wird angenommen, die
     * Meldung liegt danach ausgewertet vor, und nichts davon ist unterwegs
     * verworfen worden.
     *
     * @param  array<string, mixed>  $aufnahme
     */
    #[DataProvider('aufnahmen')]
    public function test_a_recorded_report_from_an_official_sdk_arrives_completely(array $aufnahme): void
    {
        // Die Uhr auf den Tag nach der Aufnahme: die Zeitangaben im Mitschnitt
        // sind fest, unsere Plausibilitätsgrenzen laufen mit der Gegenwart mit.
        // Ohne das wäre der Test in einem Jahr ein anderer als heute — die
        // Meldung wäre dann zu alt, und ausgerechnet der Teil, der die
        // Verträglichkeit zeigen soll, würde als ungültig vermerkt.
        $this->travelTo(self::aufnahmeTag($aufnahme));

        $key = Project::factory()->create()->keys()->firstOrFail();

        $response = $this->senden($key, $aufnahme);

        $response->assertOk();

        $payloads = IngestPayload::query()->get();

        $this->assertGreaterThan(0, $payloads->count(), 'Die Meldung wurde nicht angenommen.');

        foreach ($payloads as $payload) {
            $this->assertSame($key->project_id, $payload->project_id);
            $this->assertSame($key->id, $payload->project_key_id);

            // Der Klon erkennt, welches SDK meldet — daran hängen später die
            // Auswertungen je SDK, und es ist die knappste Probe darauf, dass
            // die Kopfzeile richtig gelesen wurde.
            $this->assertSame($aufnahme['sdk_kennung'], $payload->sdk);

            $this->assertNotSame(
                ProcessingState::Failed,
                $payload->processing_state,
                'Die Verarbeitung ist gescheitert: '.($payload->failure ?? ''),
            );
        }

        // „Vollständig" heißt: nichts ist unterwegs liegen geblieben. Ein
        // unbekannter Element-Typ, eine unlesbare Meldung, zu viele
        // Einzelschritte — alles davon wird gezählt, und jede dieser Zahlen wäre
        // hier ein Teil der Meldung, der nicht angekommen ist.
        $this->assertSame(
            [],
            IngestDiscard::query()->get()->map->only(['reason', 'category', 'quantity'])->all(),
            'Es wurde etwas verworfen.',
        );

        match ($aufnahme['art']) {
            'fehler' => $this->fehlerAngekommen(),
            'nachricht' => $this->nachrichtAngekommen(),
            'transaktion' => $this->transaktionAngekommen($key),
            'sitzung' => $this->sitzungAngekommen(),
            default => $this->fail('Unbekannte Art einer Aufnahme: '.$aufnahme['art']),
        };
    }

    /**
     * Was die Sammlung abdecken muss. Der Nachweis besteht nicht aus einer
     * einzelnen Anfrage, sondern aus einer Menge — und die kann beim
     * Neuaufnehmen unbemerkt schrumpfen, wenn etwa ein SDK nichts geschickt hat.
     * Dann soll dieser Test scheitern und nicht die Zahl der Fälle still von
     * fünfzehn auf zwölf sinken.
     */
    public function test_the_recordings_cover_the_sdks_and_kinds_the_proof_needs(): void
    {
        $verzeichnis = self::verzeichnis();

        $sdks = array_unique(array_column($verzeichnis, 'sdk'));
        $arten = array_unique(array_column($verzeichnis, 'art'));

        $this->assertGreaterThanOrEqual(3, count($sdks), 'Es sind weniger als drei SDKs aufgenommen.');

        foreach (['fehler', 'nachricht', 'transaktion', 'sitzung'] as $art) {
            $this->assertContains($art, $arten, "Für die Art „{$art}\" fehlt eine Aufnahme.");
        }

        foreach ($verzeichnis as $aufnahme) {
            $this->assertFileExists(self::VORLAGEN.'/'.$aufnahme['datei']);
            $this->assertStringNotContainsStringIgnoringCase(
                'sentry.io',
                (string) file_get_contents(self::VORLAGEN.'/'.$aufnahme['datei']),
                'Die Aufnahme ging nicht an den Klon.',
            );
        }
    }

    /**
     * Ein Fehler ist angekommen, wenn die ganze Kette dasteht: die Ursache, die
     * daraus geworfene Ausnahme, beide mit Meldung und Stacktrace. Alle vier
     * Beispiele werfen dieselbe Kette aus zwei Gliedern — und alle vier SDKs
     * schicken sie in derselben Reihenfolge, von der Ursache zur zuletzt
     * geworfenen Ausnahme. Genau darauf verlässt sich die Anzeige.
     */
    private function fehlerAngekommen(): void
    {
        $event = Event::query()->sole();

        $this->assertCount(2, $event->exceptions, 'Die Ursachenkette kam nicht vollständig an.');

        [$ursache, $geworfen] = $event->exceptions;

        $this->assertStringContainsString('Rechnungsnummer 4711', (string) ($ursache['value'] ?? ''));
        $this->assertStringContainsString('Rechnung konnte nicht', (string) ($geworfen['value'] ?? ''));

        foreach ([$ursache, $geworfen] as $ausnahme) {
            $this->assertNotEmpty($ausnahme['type'] ?? null);
            $this->assertNotEmpty($ausnahme['frames'] ?? [], 'Die Ausnahme kam ohne Stacktrace an.');
        }

        $this->assertSame('compat@1.0.0', $event->release);
        $this->assertSame('compat', $event->environment);
        $this->assertSame('4711', $event->user['id'] ?? null);
        $this->assertNotEmpty($event->title);

        // Die Gruppierung und der Eintrag darüber gehören zum Ankommen: erst mit
        // ihnen ist der Fehler in der Anwendung zu sehen.
        $this->assertNotNull($event->event_group_id);
        $this->assertSame(1, Issue::query()->sole()->times_seen);
    }

    /**
     * Eine Nachricht hat keinen Stacktrace, aber einen Text — und der ist das
     * Einzige, was sie mitbringt.
     */
    private function nachrichtAngekommen(): void
    {
        $event = Event::query()->sole();

        $this->assertStringContainsString(
            'Kompatibilitätsprobe',
            (string) ($event->message['formatted'] ?? ''),
        );

        $this->assertSame('compat@1.0.0', $event->release);
        $this->assertNotNull($event->event_group_id);
    }

    /**
     * Eine Transaktion ist angekommen, wenn Name, Dauer und Einzelschritte
     * dastehen. Wie viele Schritte es sind, steht nicht fest: der Seitenaufruf
     * im Browser bringt Dutzende mit, das Skript in Node zwei.
     */
    private function transaktionAngekommen(ProjectKey $key): void
    {
        $transaction = Transaction::query()->sole();

        $this->assertSame($key->project_id, $transaction->project_id);
        $this->assertNotEmpty($transaction->name);
        $this->assertGreaterThan(0, $transaction->duration_us, 'Die Transaktion kam ohne Dauer an.');
        $this->assertGreaterThan(0, $transaction->span_count, 'Die Transaktion kam ohne Einzelschritte an.');
        $this->assertSame($transaction->span_count, $transaction->spans()->count());
        $this->assertSame(32, strlen($transaction->trace_id));
    }

    /**
     * Sitzungen wertet der Klon noch nicht aus (Release Health ist ein eigener
     * Schritt). „Angekommen" heißt hier deshalb: als Sitzung erkannt und
     * unverändert abgelegt, mit allem, was Release Health später braucht.
     */
    private function sitzungAngekommen(): void
    {
        $payload = IngestPayload::query()->where('type', IngestType::Session)->sole();

        $sitzung = $payload->decoded();

        $this->assertNotEmpty($sitzung['sid'] ?? null, 'Die Sitzung kam ohne Kennung an.');
        $this->assertNotEmpty($sitzung['status'] ?? null);
        $this->assertNotEmpty($sitzung['started'] ?? null);
        $this->assertSame('compat@1.0.0', $sitzung['attrs']['release'] ?? null);
    }

    /**
     * Setzt die Aufnahme als Anfrage ab — mit ihren Kopfzeilen, ihrem
     * Abfrageteil und ihrer Verpackung.
     *
     * @param  array<string, mixed>  $aufnahme
     * @return TestResponse<Response>
     */
    private function senden(ProjectKey $key, array $aufnahme): TestResponse
    {
        $ersetzen = fn (string $wert): string => str_replace(
            self::AUFNAHME_SCHLUESSEL,
            $key->public_key,
            $wert,
        );

        // Die Projektnummer im Pfad ist die des aufgenommenen Projekts und muss
        // die des hier angelegten werden — sie ist Teil der Adresse, nicht der
        // Meldung.
        $pfad = (string) preg_replace(
            '#^/api/\d+/#',
            "/api/{$key->project_id}/",
            (string) $aufnahme['pfad'],
        );

        $abfrage = is_string($aufnahme['abfrage'] ?? null) ? '?'.$ersetzen($aufnahme['abfrage']) : '';

        /** @var array<string, string> $kopfzeilen */
        $kopfzeilen = is_array($aufnahme['kopfzeilen'] ?? null) ? $aufnahme['kopfzeilen'] : [];

        $rumpf = (string) file_get_contents(self::VORLAGEN.'/'.$aufnahme['datei']);

        // Aufgezeichnet ist der Envelope im Klartext, damit er lesbar und
        // vergleichbar bleibt. Hatte das SDK ihn gepackt, wird er hier wieder
        // gepackt — sonst würde der Test genau den Weg auslassen, den die
        // echte Anfrage genommen hat.
        if (($kopfzeilen['content-encoding'] ?? null) === 'gzip') {
            $rumpf = (string) gzencode($rumpf);
        }

        return $this->call(
            (string) $aufnahme['methode'],
            $pfad.$abfrage,
            server: $this->transformHeadersToServerVars(array_map($ersetzen, $kopfzeilen)),
            content: $rumpf,
        );
    }

    /**
     * Der Tag nach der Aufnahme, als Zeitpunkt für die Uhr des Tests: damit
     * liegen die Zeitangaben der Aufnahme in der Vergangenheit — wo eine
     * Meldung hingehört —, aber nicht weiter zurück als einen Tag.
     *
     * @param  array<string, mixed>  $aufnahme
     */
    private static function aufnahmeTag(array $aufnahme): CarbonImmutable
    {
        $tag = CarbonImmutable::createFromFormat('Ymd', (string) ($aufnahme['aufgezeichnet_am'] ?? ''), 'UTC');

        return ($tag ?: CarbonImmutable::now())->addDay()->startOfDay();
    }
}
