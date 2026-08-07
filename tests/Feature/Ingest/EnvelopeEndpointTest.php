<?php

namespace Tests\Feature\Ingest;

use App\Enums\DiscardOrigin;
use App\Enums\DiscardReason;
use App\Enums\IngestType;
use App\Models\IngestDiscard;
use App\Models\IngestPayload;
use App\Models\Project;
use App\Models\ProjectKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Der Envelope-Endpunkt `POST /api/{projekt}/envelope/`.
 *
 * Zwei Dinge werden hier geprüft, und beide sind Verträglichkeit mit echten
 * SDKs: dass ein mehrteiliger Envelope vollständig ankommt, und dass ein
 * einzelnes kaputtes oder unbekanntes Element die übrigen nicht mitnimmt. Der
 * zweite Punkt ist der wichtigere — ein SDK schickt einen abgewiesenen Envelope
 * nicht noch einmal.
 */
class EnvelopeEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function key(): ProjectKey
    {
        return Project::factory()->create()->keys()->firstOrFail();
    }

    private function url(ProjectKey $key): string
    {
        return "/api/{$key->project_id}/envelope/";
    }

    /**
     * Setzt einen Envelope aus Zeilen zusammen — mit abschließendem Umbruch,
     * wie ihn die meisten SDKs schreiben.
     *
     * @param  list<string>  $lines
     */
    private function envelope(array $lines): string
    {
        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function send(ProjectKey $key, string $body, array $headers = []): TestResponse
    {
        return $this->call(
            'POST',
            $this->url($key),
            server: $this->transformHeadersToServerVars([
                'X-Sentry-Auth' => "Sentry sentry_version=7, sentry_client=sentry.php/4.0.0, sentry_key={$key->public_key}",
                'Content-Type' => 'application/x-sentry-envelope',
            ] + $headers),
            content: $body,
        );
    }

    /**
     * Der Regelfall heutiger SDKs: Fehler und Transaktion in einer Anfrage.
     */
    public function test_a_multi_part_envelope_produces_one_record_per_item(): void
    {
        $key = $this->key();
        $eventId = IngestPayload::freshEventId();

        $response = $this->send($key, $this->envelope([
            '{"event_id":"'.$eventId.'","sent_at":"2026-08-07T10:00:00Z"}',
            '{"type":"event"}',
            '{"event_id":"'.$eventId.'","message":"Kaputt"}',
            '{"type":"transaction"}',
            '{"transaction":"GET /kunden","spans":[]}',
        ]));

        $response->assertStatus(200)->assertExactJson(['id' => $eventId]);

        $this->assertSame(2, IngestPayload::query()->count());

        $event = IngestPayload::query()->where('type', IngestType::Event)->sole();
        $this->assertSame($eventId, $event->event_id);
        $this->assertSame($key->project_id, $event->project_id);
        $this->assertSame($key->id, $event->project_key_id);
        $this->assertSame('{"event_id":"'.$eventId.'","message":"Kaputt"}', $event->payload);

        $transaction = IngestPayload::query()->where('type', IngestType::Transaction)->sole();
        $this->assertSame('GET /kunden', $transaction->decoded()['transaction'] ?? null);

        // Die Transaktion bringt keine eigene Nummer mit und erbt deshalb die
        // des Envelope — so gehören beide später zusammen.
        $this->assertSame($eventId, $transaction->event_id);
    }

    /**
     * Alle Typen aus der Spezifikation müssen erkannt werden. Ein einziger
     * übersehener Typ heißt: dieses Feature bekommt nie Daten, ohne dass
     * irgendwo ein Fehler auftaucht.
     */
    public function test_every_specified_item_type_is_recognised(): void
    {
        $key = $this->key();

        $types = [
            'event', 'transaction', 'session', 'sessions', 'attachment',
            'check_in', 'replay_event', 'replay_recording', 'profile',
            'client_report', 'user_report',
        ];

        $lines = ['{}'];

        foreach ($types as $type) {
            $lines[] = '{"type":"'.$type.'"}';
            $lines[] = '{"nutzdaten":"'.$type.'"}';
        }

        $this->send($key, $this->envelope($lines))->assertStatus(200);

        $this->assertSame(count($types), IngestPayload::query()->count());

        foreach ($types as $type) {
            $this->assertDatabaseHas('ingest_payloads', ['type' => $type]);
        }

        $this->assertSame(0, IngestDiscard::query()->count());
    }

    /**
     * Sentry erweitert die Liste der Element-Typen laufend. Ein unbekannter Typ
     * ist deshalb ein normaler Vorgang: er wird gezählt, verworfen — und die
     * Anfrage bleibt erfolgreich.
     */
    public function test_an_unknown_item_type_is_counted_and_dropped_without_failing_the_request(): void
    {
        $key = $this->key();

        $response = $this->send($key, $this->envelope([
            '{}',
            '{"type":"event"}',
            '{"message":"heil"}',
            '{"type":"erfundener_typ"}',
            '{"egal":true}',
        ]));

        $response->assertStatus(200);

        $this->assertSame(IngestType::Event, IngestPayload::query()->sole()->type);

        $discard = IngestDiscard::query()->sole();
        $this->assertSame(DiscardOrigin::Server, $discard->origin);
        $this->assertSame(DiscardReason::UnknownType->value, $discard->reason);
        $this->assertSame('erfundener_typ', $discard->category);
        $this->assertSame(1, $discard->quantity);
    }

    /**
     * Zweimal dasselbe verworfen heißt: ein Zähler auf 2, nicht zwei Zeilen.
     */
    public function test_repeated_discards_of_the_same_kind_share_one_counter(): void
    {
        $key = $this->key();

        for ($i = 0; $i < 2; $i++) {
            $this->send($key, $this->envelope([
                '{}',
                '{"type":"span"}',
                '{"egal":true}',
            ]))->assertStatus(200);
        }

        $this->assertSame(2, IngestDiscard::query()->sole()->quantity);
    }

    /**
     * Ein Element ohne lesbaren Kopf beendet das Zerlegen — was davor stand,
     * ist trotzdem angekommen.
     */
    public function test_a_broken_item_is_dropped_alone_and_logged(): void
    {
        Log::spy();

        $key = $this->key();

        $this->send($key, $this->envelope([
            '{}',
            '{"type":"event"}',
            '{"message":"heil"}',
            'das ist kein kopf',
            '{"noch":"was"}',
        ]))->assertStatus(200);

        $this->assertSame(1, IngestPayload::query()->count());
        $this->assertSame(DiscardReason::Unreadable->value, IngestDiscard::query()->sole()->reason);

        Log::shouldHaveReceived('warning')->once();
    }

    /**
     * Ein JSON-Element, dessen Nutzdaten kein Objekt sind, kann niemand
     * verarbeiten — es fliegt für sich heraus.
     */
    public function test_an_item_whose_payload_is_not_a_json_object_is_dropped_alone(): void
    {
        $key = $this->key();

        $this->send($key, $this->envelope([
            '{}',
            '{"type":"event","length":14}',
            '"nur ein text"',
            '{"type":"session"}',
            '{"sid":"heil"}',
        ]))->assertStatus(200);

        $this->assertSame(IngestType::Session, IngestPayload::query()->sole()->type);
        $this->assertSame(DiscardReason::Unreadable->value, IngestDiscard::query()->sole()->reason);
    }

    /**
     * Ein Anhang ist der Fall, für den die Längenangabe im Element-Kopf da ist:
     * Binärdaten enthalten Zeilenumbrüche und Nullbytes. Beides muss die Ablage
     * unbeschadet überstehen, sonst ist der Screenshot beim Herausholen kaputt.
     */
    public function test_a_binary_attachment_survives_storage_byte_for_byte(): void
    {
        $key = $this->key();
        $eventId = IngestPayload::freshEventId();
        $binary = "\x89PNG\r\n\x1a\n\x00\x00\x00\x0dNICHT\nTEXT\xff\xfe";

        $this->send($key, implode('', [
            '{"event_id":"'.$eventId.'"}'."\n",
            '{"type":"attachment","length":'.strlen($binary).',"filename":"bild.png","content_type":"image/png"}'."\n",
            $binary."\n",
            '{"type":"event"}'."\n",
            '{"message":"dazu"}'."\n",
        ]))->assertStatus(200);

        $attachment = IngestPayload::query()->where('type', IngestType::Attachment)->sole();

        $this->assertSame($binary, $attachment->bytes());
        $this->assertSame(IngestPayload::ENCODING_BASE64, $attachment->payload_encoding);
        $this->assertSame(strlen($binary), $attachment->size_bytes);
        $this->assertSame('bild.png', $attachment->filename());
        $this->assertSame('image/png', $attachment->contentType());

        // Der Anhang gehört zu der Meldung, mit der er kam.
        $this->assertSame($eventId, $attachment->event_id);
    }

    /**
     * Eine Textdatei als Anhang bleibt Text: verpackt wird am Inhalt
     * entschieden, nicht am Typ. Sonst wäre jedes Logfile in der Datenbank
     * unlesbar.
     */
    public function test_a_text_attachment_is_stored_as_it_came(): void
    {
        $key = $this->key();
        $text = "erste Zeile\nzweite Zeile mit Umlauten: äöü\n";

        $this->send($key, implode('', [
            '{}'."\n",
            '{"type":"attachment","length":'.strlen($text).',"filename":"log.txt"}'."\n",
            $text,
        ]))->assertStatus(200);

        $stored = IngestPayload::query()->sole();

        $this->assertNull($stored->payload_encoding);
        $this->assertSame($text, $stored->payload);
        $this->assertSame($text, $stored->bytes());
    }

    /**
     * Die Verworfen-Meldung des SDK ist die einzige Auskunft darüber, was gar
     * nicht erst abgeschickt wurde. Sie wird abgelegt **und** in die Zählung
     * übernommen — läge sie nur als Rohdatensatz herum, bliebe die Frage
     * „warum fehlen Meldungen?" unbeantwortet.
     */
    public function test_a_client_report_is_stored_and_folded_into_the_statistics(): void
    {
        $key = $this->key();

        $report = (string) json_encode([
            'timestamp' => '2026-08-07T10:00:00Z',
            'discarded_events' => [
                ['reason' => 'queue_overflow', 'category' => 'error', 'quantity' => 23],
                ['reason' => 'ratelimit_backoff', 'category' => 'transaction', 'quantity' => 4],
            ],
        ]);

        $this->send($key, $this->envelope([
            '{}',
            '{"type":"client_report","length":'.strlen($report).'}',
            $report,
        ]))->assertStatus(200);

        $this->assertSame(IngestType::ClientReport, IngestPayload::query()->sole()->type);

        $this->assertDatabaseHas('ingest_discards', [
            'project_id' => $key->project_id,
            'origin' => DiscardOrigin::Client->value,
            'reason' => 'queue_overflow',
            'category' => 'error',
            'quantity' => 23,
        ]);

        $this->assertDatabaseHas('ingest_discards', [
            'origin' => DiscardOrigin::Client->value,
            'reason' => 'ratelimit_backoff',
            'category' => 'transaction',
            'quantity' => 4,
        ]);
    }

    /**
     * Unbrauchbare Einträge in der Verworfen-Meldung werden übergangen, nicht
     * geraten: eine erfundene Zahl in der Statistik wäre schlimmer als eine
     * fehlende.
     */
    public function test_unusable_entries_in_a_client_report_are_skipped(): void
    {
        $key = $this->key();

        $report = (string) json_encode([
            'discarded_events' => [
                ['reason' => 'ohne_anzahl', 'category' => 'error'],
                ['category' => 'error', 'quantity' => 5],
                ['reason' => 'brauchbar', 'category' => 'error', 'quantity' => 7],
            ],
        ]);

        $this->send($key, $this->envelope([
            '{}',
            '{"type":"client_report","length":'.strlen($report).'}',
            $report,
        ]))->assertStatus(200);

        $discard = IngestDiscard::query()->sole();
        $this->assertSame('brauchbar', $discard->reason);
        $this->assertSame(7, $discard->quantity);
    }

    /**
     * Ein Envelope aus lauter Sitzungen hat keine Meldungsnummer. Dann bleibt
     * die Antwort ein leeres Objekt — eine erfundene Nummer wäre die
     * schlechtere Auskunft.
     */
    public function test_an_envelope_without_an_event_id_answers_with_an_empty_object(): void
    {
        $key = $this->key();

        $response = $this->send($key, $this->envelope([
            '{"sent_at":"2026-08-07T10:00:00Z"}',
            '{"type":"session"}',
            '{"sid":"3c2e1a","status":"ok"}',
        ]));

        $response->assertStatus(200);
        $this->assertSame('{}', $response->getContent());

        // Eine Kennung bekommt der Datensatz trotzdem — sonst wäre er nicht
        // ansprechbar.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', IngestPayload::query()->sole()->event_id);
    }

    public function test_a_header_only_envelope_is_accepted_and_stores_nothing(): void
    {
        $key = $this->key();

        $this->send($key, '{"sent_at":"2026-08-07T10:00:00Z"}')->assertStatus(200);

        $this->assertSame(0, IngestPayload::query()->count());
        $this->assertSame(0, IngestDiscard::query()->count());
    }

    /**
     * Ohne lesbare Kopfzeile ist es kein Envelope — das ist der einzige Fall,
     * in dem die ganze Anfrage abgewiesen wird.
     */
    public function test_an_envelope_without_a_readable_header_is_rejected(): void
    {
        $key = $this->key();

        $this->send($key, "kein json\n{\"type\":\"event\"}\n{}")
            ->assertStatus(400)
            ->assertHeader('X-Sentry-Error');
    }

    public function test_the_address_works_with_and_without_a_trailing_slash(): void
    {
        $key = $this->key();
        $body = $this->envelope(['{}', '{"type":"event"}', '{"message":"x"}']);

        $this->send($key, $body)->assertStatus(200);

        $this->call(
            'POST',
            "/api/{$key->project_id}/envelope",
            server: $this->transformHeadersToServerVars([
                'X-Sentry-Auth' => "Sentry sentry_version=7, sentry_key={$key->public_key}",
            ]),
            content: $body,
        )->assertStatus(200);

        $this->assertSame(2, IngestPayload::query()->count());
    }

    /**
     * Das Browser-SDK schickt den Schlüssel im Abfrageteil, weil eine eigene
     * Kopfzeile eine Vorab-Anfrage erzwingen würde.
     */
    public function test_the_public_key_may_come_from_the_query_string(): void
    {
        $key = $this->key();

        $this->call(
            'POST',
            $this->url($key).'?sentry_key='.$key->public_key.'&sentry_version=7',
            content: $this->envelope(['{}', '{"type":"event"}', '{"message":"x"}']),
        )->assertStatus(200);

        $this->assertSame(1, IngestPayload::query()->count());
    }

    public function test_an_unknown_key_is_not_authorized(): void
    {
        $key = $this->key();

        $this->call(
            'POST',
            $this->url($key).'?sentry_key=gibtesnicht',
            content: $this->envelope(['{}']),
        )->assertStatus(401);
    }

    /**
     * SDKs packen ihre Envelopes; ohne das Entpacken käme kein einziger an.
     */
    public function test_a_gzipped_envelope_is_unpacked(): void
    {
        $key = $this->key();
        $body = $this->envelope(['{}', '{"type":"event"}', '{"message":"gepackt"}']);

        $this->send($key, (string) gzencode($body), ['Content-Encoding' => 'gzip'])
            ->assertStatus(200);

        $this->assertSame('gepackt', IngestPayload::query()->sole()->decoded()['message'] ?? null);
    }

    /**
     * Der Envelope-Kopf nennt das SDK genauer als die Zugangsdaten — er kommt
     * von dem SDK, das den Envelope gebaut hat.
     */
    public function test_the_sdk_from_the_envelope_header_wins_over_the_credentials(): void
    {
        $key = $this->key();

        $this->send($key, $this->envelope([
            '{"sdk":{"name":"sentry.javascript.browser","version":"8.0.0"}}',
            '{"type":"event"}',
            '{"message":"x"}',
        ]))->assertStatus(200);

        $this->assertSame('sentry.javascript.browser/8.0.0', IngestPayload::query()->sole()->sdk);
    }

    public function test_the_credentials_name_the_sdk_when_the_envelope_header_does_not(): void
    {
        $key = $this->key();

        $this->send($key, $this->envelope(['{}', '{"type":"event"}', '{"message":"x"}']))
            ->assertStatus(200);

        $this->assertSame('sentry.php/4.0.0', IngestPayload::query()->sole()->sdk);
    }

    /**
     * Ein einzelnes zu großes Element fliegt heraus — die übrigen kommen an.
     * Andernfalls würde ein überlanger Anhang die Fehlermeldung mitnehmen, mit
     * der er zusammen gesendet wurde.
     */
    public function test_an_oversized_item_is_dropped_alone(): void
    {
        config(['ingest.envelope.max_item_bytes' => 64]);

        $key = $this->key();
        $long = (string) json_encode(['message' => str_repeat('x', 200)]);

        $this->send($key, $this->envelope([
            '{}',
            '{"type":"event","length":'.strlen($long).'}',
            $long,
            '{"type":"session"}',
            '{"sid":"heil"}',
        ]))->assertStatus(200);

        $this->assertSame(IngestType::Session, IngestPayload::query()->sole()->type);

        $discard = IngestDiscard::query()->sole();
        $this->assertSame(DiscardReason::TooLarge->value, $discard->reason);
        $this->assertSame('event', $discard->category);
    }

    /**
     * Anhänge haben ihre eigene, höhere Grenze: für sie ist Größe der
     * Normalfall.
     */
    public function test_attachments_have_their_own_size_limit(): void
    {
        config([
            'ingest.envelope.max_item_bytes' => 64,
            'ingest.envelope.max_attachment_bytes' => 4096,
        ]);

        $key = $this->key();
        $attachment = str_repeat('A', 1000);

        $this->send($key, $this->envelope([
            '{}',
            '{"type":"attachment","length":'.strlen($attachment).'}',
            $attachment,
        ]))->assertStatus(200);

        $this->assertSame(IngestType::Attachment, IngestPayload::query()->sole()->type);
        $this->assertSame(0, IngestDiscard::query()->count());
    }

    /**
     * Ein Envelope mit sehr vielen Elementen wird gekappt, nicht abgewiesen:
     * was hineinpasst, kommt an.
     */
    public function test_too_many_items_are_capped_and_counted(): void
    {
        config(['ingest.envelope.max_items' => 3]);

        $key = $this->key();
        $lines = ['{}'];

        for ($i = 0; $i < 10; $i++) {
            $lines[] = '{"type":"event"}';
            $lines[] = '{"message":"nummer '.$i.'"}';
        }

        $this->send($key, $this->envelope($lines))->assertStatus(200);

        $this->assertSame(3, IngestPayload::query()->count());

        $discard = IngestDiscard::query()->sole();
        $this->assertSame(DiscardReason::TooManyItems->value, $discard->reason);
        $this->assertSame(7, $discard->quantity);
    }

    /**
     * Ein Envelope jenseits der Gesamtgrenze wird als Ganzes abgewiesen — bis
     * dorthin ist nichts entpackt und nichts geprüft.
     */
    public function test_an_oversized_envelope_is_rejected(): void
    {
        config(['ingest.envelope.max_request_bytes' => 128]);

        $key = $this->key();

        $this->send($key, $this->envelope([
            '{}',
            '{"type":"event"}',
            (string) json_encode(['message' => str_repeat('x', 500)]),
        ]))->assertStatus(413);

        $this->assertSame(0, IngestPayload::query()->count());
    }

    /**
     * Ein Schlüssel darf nur in sein eigenes Projekt einliefern — sonst
     * genügte es, eine andere Nummer in die Adresse zu schreiben.
     */
    public function test_a_key_cannot_deliver_into_another_project(): void
    {
        $key = $this->key();
        $other = Project::factory()->create();

        $this->call(
            'POST',
            "/api/{$other->id}/envelope/?sentry_key={$key->public_key}",
            content: $this->envelope(['{}', '{"type":"event"}', '{"message":"x"}']),
        )->assertStatus(401);

        $this->assertSame(0, IngestPayload::query()->count());
    }
}
