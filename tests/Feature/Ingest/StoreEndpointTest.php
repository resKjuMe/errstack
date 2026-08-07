<?php

namespace Tests\Feature\Ingest;

use App\Enums\IngestType;
use App\Models\IngestPayload;
use App\Models\Project;
use App\Models\ProjectKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Der klassische Aufnahme-Endpunkt `POST /api/{projekt}/store/`.
 *
 * Geprüft wird vor allem die Verträglichkeit mit echten SDKs: Adresse mit
 * abschließendem Schrägstrich, alle drei Wege für die Zugangsdaten, gepackte
 * Rümpfe, und die Antwortform `{"id": …}`. Jede Abweichung davon ist ein SDK,
 * dessen Meldungen nicht ankommen — deshalb steht das hier so ausführlich.
 */
class StoreEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function key(): ProjectKey
    {
        $project = Project::factory()->create();

        // Die Factory legt den ersten Schlüssel mit an — denselben, den ein
        // frisches Projekt in der Oberfläche zeigt.
        return $project->keys()->firstOrFail();
    }

    private function url(ProjectKey $key): string
    {
        return "/api/{$key->project_id}/store/";
    }

    /**
     * Zugangsdaten, wie ein Server-SDK sie schickt.
     *
     * @return array<string, string>
     */
    private function sentryAuth(ProjectKey $key, string $client = 'sentry.php/4.0.0'): array
    {
        return [
            'X-Sentry-Auth' => "Sentry sentry_version=7, sentry_client={$client}, sentry_key={$key->public_key}",
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function event(?string $eventId = null): array
    {
        return array_filter([
            'event_id' => $eventId,
            'timestamp' => now()->toIso8601String(),
            'platform' => 'php',
            'level' => 'error',
            'message' => 'Etwas ist kaputt.',
        ]);
    }

    public function test_a_valid_report_is_accepted_and_confirmed_with_its_event_id(): void
    {
        $key = $this->key();
        $eventId = IngestPayload::freshEventId();

        $response = $this->call(
            'POST',
            $this->url($key),
            server: $this->serverHeaders($this->sentryAuth($key) + ['Content-Type' => 'application/json']),
            content: (string) json_encode($this->event($eventId)),
        );

        $response->assertStatus(200)->assertExactJson(['id' => $eventId]);

        $stored = IngestPayload::query()->sole();

        $this->assertSame($key->project_id, $stored->project_id);
        $this->assertSame($key->id, $stored->project_key_id);
        $this->assertSame($eventId, $stored->event_id);
        $this->assertSame(IngestType::Event, $stored->type);
        $this->assertSame('sentry.php/4.0.0', $stored->sdk);
        $this->assertSame(strlen($stored->payload), $stored->size_bytes);

        $decoded = $stored->decoded() ?? [];
        $this->assertSame('Etwas ist kaputt.', $decoded['message'] ?? null);
    }

    /**
     * Der Rumpf bleibt Zeichen für Zeichen liegen, wie er hereinkam. Würde er
     * beim Ablegen neu formatiert, ließen sich später keine Signaturen mehr
     * darüber bilden und kein Original mehr vorzeigen.
     */
    public function test_the_stored_payload_is_the_untouched_original(): void
    {
        $key = $this->key();
        $body = '{"event_id":"'.IngestPayload::freshEventId().'",  "message":"Roh" , "extra":{"a":1}}';

        $this->postRaw($key, $body)->assertStatus(200);

        $this->assertSame($body, IngestPayload::query()->sole()->payload);
    }

    /**
     * SDKs melden an die Adresse aus der DSN, und die endet auf einen
     * Schrägstrich. Ohne diesen Fall wäre die Kompatibilität nur behauptet.
     */
    public function test_the_address_works_with_and_without_a_trailing_slash(): void
    {
        $key = $this->key();

        $this->postRaw($key, (string) json_encode($this->event()))->assertStatus(200);

        $this->call(
            'POST',
            "/api/{$key->project_id}/store",
            server: $this->serverHeaders($this->sentryAuth($key)),
            content: (string) json_encode($this->event()),
        )->assertStatus(200);

        $this->assertSame(2, IngestPayload::query()->count());
    }

    public function test_a_missing_event_id_is_assigned_and_returned(): void
    {
        $key = $this->key();

        $response = $this->postRaw($key, (string) json_encode($this->event()));

        $eventId = (string) $response->assertStatus(200)->json('id');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $eventId);
        $this->assertSame($eventId, IngestPayload::query()->sole()->event_id);
    }

    /**
     * Manche SDKs schicken die Nummer als UUID mit Bindestrichen. Ohne
     * Vereinheitlichung gälte dieselbe Meldung bei der späteren Doppelerkennung
     * als zwei verschiedene.
     */
    public function test_an_event_id_with_dashes_is_normalized(): void
    {
        $key = $this->key();

        $response = $this->postRaw($key, (string) json_encode([
            'event_id' => 'A1B2C3D4-E5F6-4a7b-8c9d-0e1f2a3b4c5d',
            'message' => 'Mit Bindestrichen',
        ]));

        $response->assertStatus(200)->assertExactJson(['id' => 'a1b2c3d4e5f64a7b8c9d0e1f2a3b4c5d']);
    }

    public function test_an_unusable_event_id_is_replaced_instead_of_rejected(): void
    {
        $key = $this->key();

        $response = $this->postRaw($key, (string) json_encode([
            'event_id' => 'nicht-hexadezimal',
            'message' => 'Trotzdem eine Meldung',
        ]));

        // Angenommen wird sie: eine kaputte Nummer ist kein Grund, den Fehler
        // der überwachten Anwendung zu verlieren.
        $eventId = (string) $response->assertStatus(200)->json('id');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $eventId);
    }

    public function test_the_public_key_may_come_from_the_query_string(): void
    {
        $key = $this->key();

        $this->call(
            'POST',
            $this->url($key).'?sentry_key='.$key->public_key,
            content: (string) json_encode($this->event()),
        )->assertStatus(200);
    }

    public function test_the_public_key_may_come_as_a_bearer_token(): void
    {
        $key = $this->key();

        $this->call(
            'POST',
            $this->url($key),
            server: $this->serverHeaders(['Authorization' => 'Bearer '.$key->public_key]),
            content: (string) json_encode($this->event()),
        )->assertStatus(200);
    }

    public function test_an_unknown_key_is_not_authorized(): void
    {
        $key = $this->key();

        $this->call(
            'POST',
            $this->url($key),
            server: $this->serverHeaders(['X-Sentry-Auth' => 'Sentry sentry_version=7, sentry_key='.ProjectKey::freshPublicKey()]),
            content: (string) json_encode($this->event()),
        )
            ->assertStatus(401)
            ->assertHeader('X-Sentry-Error')
            ->assertJsonStructure(['detail']);

        $this->assertSame(0, IngestPayload::query()->count());
    }

    /**
     * Ein zurückgezogener Schlüssel gilt als unbekannt — das ist der ganze Zweck
     * des Abschaltens.
     */
    public function test_a_deactivated_key_is_not_authorized(): void
    {
        $key = $this->key();
        $key->update(['active' => false]);

        $this->postRaw($key, (string) json_encode($this->event()))->assertStatus(401);
    }

    public function test_a_request_without_any_credentials_is_not_authorized(): void
    {
        $key = $this->key();

        $this->call('POST', $this->url($key), content: (string) json_encode($this->event()))
            ->assertStatus(401);
    }

    /**
     * Der Schlüssel gilt für genau ein Projekt. Ohne diese Prüfung könnte jeder
     * gültige Schlüssel in fremde Projekte melden — es genügte, eine andere
     * Nummer in die Adresse zu schreiben.
     */
    public function test_a_key_cannot_report_into_another_project(): void
    {
        $key = $this->key();
        $other = Project::factory()->create();

        $this->call(
            'POST',
            "/api/{$other->id}/store/",
            server: $this->serverHeaders($this->sentryAuth($key)),
            content: (string) json_encode($this->event()),
        )->assertStatus(401);

        $this->assertSame(0, IngestPayload::query()->count());
    }

    public function test_a_gzipped_body_is_unpacked(): void
    {
        $key = $this->key();
        $json = (string) json_encode($this->event());

        $this->postRaw($key, (string) gzencode($json), ['Content-Encoding' => 'gzip'])
            ->assertStatus(200);

        $this->assertSame($json, IngestPayload::query()->sole()->payload);
    }

    public function test_a_deflated_body_is_unpacked(): void
    {
        $key = $this->key();
        $json = (string) json_encode($this->event());

        $this->postRaw($key, (string) gzcompress($json), ['Content-Encoding' => 'deflate'])
            ->assertStatus(200);

        $this->assertSame($json, IngestPayload::query()->sole()->payload);
    }

    /**
     * Alte SDKs schicken Base64 über einem deflate-Strom, ohne das
     * anzukündigen — als `application/octet-stream`.
     */
    public function test_a_base64_encoded_deflate_body_is_unpacked(): void
    {
        $key = $this->key();
        $json = (string) json_encode($this->event());

        $this->postRaw(
            $key,
            base64_encode((string) gzcompress($json)),
            ['Content-Type' => 'application/octet-stream'],
        )->assertStatus(200);

        $this->assertSame($json, IngestPayload::query()->sole()->payload);
    }

    /**
     * Zwischenstationen entpacken gelegentlich und lassen die Kopfzeile stehen.
     * Dann hat der Inhalt recht, nicht die Ankündigung — sonst wäre eine
     * durchgereichte Meldung verloren.
     */
    public function test_a_wrongly_announced_encoding_does_not_lose_the_report(): void
    {
        $key = $this->key();
        $json = (string) json_encode($this->event());

        $this->postRaw($key, $json, ['Content-Encoding' => 'gzip'])->assertStatus(200);

        $this->assertSame($json, IngestPayload::query()->sole()->payload);
    }

    public function test_an_oversized_body_is_rejected(): void
    {
        $key = $this->key();
        config(['ingest.max_request_bytes' => 512]);

        $this->postRaw($key, (string) json_encode([
            'message' => str_repeat('x', 1024),
        ]))
            ->assertStatus(413)
            ->assertHeader('X-Sentry-Error');

        $this->assertSame(0, IngestPayload::query()->count());
    }

    /**
     * Der gefährlichere Fall: wenige Kilobyte, die entpackt zu Gigabyte werden.
     * Die Grenze für den gepackten Rumpf greift dabei nicht — nur die für den
     * entpackten Inhalt.
     */
    public function test_a_body_that_only_becomes_huge_after_unpacking_is_rejected(): void
    {
        $key = $this->key();
        config(['ingest.max_payload_bytes' => 2048]);

        $bomb = (string) gzencode('{"message":"'.str_repeat('x', 5_000_000).'"}');

        $this->assertLessThan(
            (int) config('ingest.max_request_bytes'),
            strlen($bomb),
            'Der gepackte Rumpf muss unter der Roh-Grenze liegen, sonst prüft der Test die falsche Schranke.',
        );

        $this->postRaw($key, $bomb, ['Content-Encoding' => 'gzip'])->assertStatus(413);

        $this->assertSame(0, IngestPayload::query()->count());
    }

    public function test_an_empty_body_is_rejected(): void
    {
        $this->postRaw($this->key(), '')->assertStatus(400);
    }

    public function test_an_unreadable_body_is_rejected(): void
    {
        $this->postRaw($this->key(), "\x00\x01nicht entpackbar\x02")->assertStatus(400);
    }

    /**
     * Eine Liste ist gültiges JSON, aber keine Meldung — und ohne diese Prüfung
     * läge sie unauswertbar in der Ablage.
     */
    public function test_a_json_body_that_is_not_an_object_is_rejected(): void
    {
        $this->postRaw($this->key(), '[{"message":"Liste"}]')->assertStatus(400);

        $this->assertSame(0, IngestPayload::query()->count());
    }

    /**
     * Das JavaScript-SDK meldet aus dem Browser des Besuchers, also von einer
     * fremden Adresse. Ohne die Freigaben verwirft der Browser die Antwort und
     * das SDK sieht nur einen Netzfehler.
     */
    public function test_the_browser_is_allowed_to_call_the_endpoint(): void
    {
        $key = $this->key();

        $this->call(
            'OPTIONS',
            $this->url($key),
            server: $this->serverHeaders([
                'Origin' => 'https://ueberwachte-anwendung.example',
                'Access-Control-Request-Method' => 'POST',
                'Access-Control-Request-Headers' => 'x-sentry-auth,content-type',
            ]),
        )
            ->assertSuccessful()
            ->assertHeader('Access-Control-Allow-Origin', '*');

        $response = $this->postRaw($key, (string) json_encode($this->event()), [
            'Origin' => 'https://ueberwachte-anwendung.example',
        ])
            ->assertStatus(200)
            ->assertHeader('Access-Control-Allow-Origin', '*');

        // Ohne diese Freigabe kommt der Grund einer Abweisung im Browser nicht
        // an. Wie die Liste getrennt wird, ist Sache der CORS-Middleware — hier
        // zählt nur, dass die Kopfzeile darin steht.
        $this->assertStringContainsString(
            'X-Sentry-Error',
            (string) $response->headers->get('Access-Control-Expose-Headers'),
        );
    }

    /**
     * Ein Aufruf mit Rumpf und Kopfzeilen, wie ein SDK ihn absetzt.
     *
     * `postJson` taugt dafür nicht: es kodiert den Rumpf selbst und käme mit
     * gepackten Daten nicht zurecht.
     *
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function postRaw(ProjectKey $key, string $body, array $headers = []): TestResponse
    {
        return $this->call(
            'POST',
            $this->url($key),
            server: $this->serverHeaders($this->sentryAuth($key) + $headers),
            content: $body,
        );
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function serverHeaders(array $headers): array
    {
        return $this->transformHeadersToServerVars($headers);
    }
}
