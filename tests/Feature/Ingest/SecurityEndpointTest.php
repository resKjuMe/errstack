<?php

namespace Tests\Feature\Ingest;

use App\Enums\DiscardOrigin;
use App\Enums\DiscardReason;
use App\Enums\IngestType;
use App\Models\IngestDiscard;
use App\Models\IngestPayload;
use App\Models\Issue;
use App\Models\Project;
use App\Models\ProjectKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Der Endpunkt für die Sicherheitsberichte des Browsers:
 * `POST /api/{projekt}/security/`.
 *
 * Geprüft wird der Weg, den ein Browser tatsächlich geht — Schlüssel im
 * Abfrageteil, `application/csp-report` als Content-Type, kein SDK, keine
 * Kopfzeilen. Jede Abweichung davon ist eine Anwendung, deren Verstöße nie
 * ankommen, und anders als bei einem SDK gibt es niemanden, der es erneut
 * versucht.
 *
 * Der zweite Teil ist das, was danach passiert: aus dem Bericht wird ein
 * gewöhnlicher Fehler-Eintrag, gruppiert nach Direktive und blockierter Quelle.
 */
class SecurityEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function key(): ProjectKey
    {
        return Project::factory()->create()->keys()->firstOrFail();
    }

    private function url(ProjectKey $key, ?string $publicKey = null): string
    {
        // Mit abschließendem Schrägstrich und mit dem Schlüssel im Abfrageteil:
        // genau so steht die Adresse in einer `report-uri`.
        return "/api/{$key->project_id}/security/?sentry_key=".($publicKey ?? $key->public_key);
    }

    /**
     * @param  array<string, mixed>  $report
     * @return TestResponse<Response>
     */
    private function report(ProjectKey $key, array $report, ?string $url = null): TestResponse
    {
        return $this->call(
            'POST',
            $url ?? $this->url($key),
            server: $this->transformHeadersToServerVars([
                'Content-Type' => 'application/csp-report',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; rv:141.0) Gecko/20100101 Firefox/141.0',
            ]),
            content: (string) json_encode($report),
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function csp(array $overrides = []): array
    {
        return ['csp-report' => $overrides + [
            'document-uri' => 'https://shop.example/kasse',
            'referrer' => '',
            'violated-directive' => "script-src 'self'",
            'effective-directive' => 'script-src',
            'original-policy' => "default-src 'self'; report-uri /api/1/security/",
            'disposition' => 'enforce',
            'blocked-uri' => 'https://werbung.example/tracker.js',
            'status-code' => 200,
        ]];
    }

    public function test_a_csp_report_is_accepted_with_the_key_from_the_query(): void
    {
        $key = $this->key();

        $response = $this->report($key, $this->csp());

        // 201 mit leerem Rumpf, wie bei Sentry: der Browser wertet die Antwort
        // nicht aus, und eine Nummer gäbe es hier nicht zu nennen.
        $response->assertStatus(201)->assertExactJson([]);

        $stored = IngestPayload::query()->sole();

        $this->assertSame($key->project_id, $stored->project_id);
        $this->assertSame($key->id, $stored->project_key_id);
        // Als gewöhnliche Meldung abgelegt — nur so läuft der Bericht durch
        // dieselbe Kette und erscheint in denselben Listen.
        $this->assertSame(IngestType::Event, $stored->type);
        $this->assertSame('errstack.security/csp', $stored->sdk);

        $decoded = $stored->decoded() ?? [];

        $this->assertSame('https://werbung.example', $decoded['culprit'] ?? null);
        $this->assertSame('csp', $decoded['tags']['security_report'] ?? null);
        // Der User-Agent kommt vom Browser des Betroffenen und ist echt: daran
        // hängen die Eingangsfilter für Crawler und veraltete Browser.
        $this->assertStringContainsString('Firefox/141.0', $decoded['request']['headers']['User-Agent'] ?? '');
    }

    public function test_a_report_without_a_valid_key_is_rejected(): void
    {
        $key = $this->key();

        $this->report($key, $this->csp(), "/api/{$key->project_id}/security/")
            ->assertStatus(401);

        $this->report($key, $this->csp(), $this->url($key, 'unbekannt'))
            ->assertStatus(401);

        $this->assertSame(0, IngestPayload::query()->count());
    }

    public function test_a_body_that_is_not_a_security_report_is_rejected(): void
    {
        $key = $this->key();

        $response = $this->report($key, ['message' => 'Etwas ist kaputt.']);

        $response->assertStatus(400);
        // Der Grund steht in der Kopfzeile, in der Sentry ihn führt — hier ist
        // sie die einzige Stelle, an der jemand ihn sucht.
        $this->assertNotEmpty($response->headers->get('X-Sentry-Error'));

        $this->assertSame(0, IngestPayload::query()->count());
    }

    public function test_reports_from_browser_extensions_are_dropped_and_counted(): void
    {
        $key = $this->key();

        $this->report($key, $this->csp([
            'blocked-uri' => 'chrome-extension://mihcahmgecmbnbcchbopgniflfhgnkff/inject.js',
        ]))->assertStatus(201);

        // Nichts abgelegt: der Bericht sagt etwas über die Installation des
        // Besuchers und nichts über die überwachte Anwendung.
        $this->assertSame(0, IngestPayload::query()->count());

        // Gezählt aber schon — sonst wäre „warum sehe ich keine Berichte?"
        // nicht zu beantworten.
        $discard = IngestDiscard::query()->sole();

        $this->assertSame(DiscardOrigin::Server, $discard->origin);
        $this->assertSame(DiscardReason::Filtered->value, $discard->reason);
        $this->assertSame('browser_extension', $discard->category);
        $this->assertSame(1, $discard->quantity);
    }

    public function test_reports_become_issues_grouped_by_directive_and_blocked_source(): void
    {
        $key = $this->key();

        // Dieselbe Quelle über zwei Seiten und zwei Dateien: ein Eintrag.
        $this->report($key, $this->csp())->assertStatus(201);
        $this->report($key, $this->csp([
            'document-uri' => 'https://shop.example/warenkorb',
            'blocked-uri' => 'https://werbung.example/pixel.gif?id=17',
        ]))->assertStatus(201);

        // Andere Direktive und andere Quelle: ein zweiter Eintrag.
        $this->report($key, $this->csp([
            'effective-directive' => 'style-src',
            'blocked-uri' => 'https://schriften.example/stil.css',
        ]))->assertStatus(201);

        $issues = Issue::query()->where('project_id', $key->project_id)->get();

        $this->assertCount(2, $issues);

        $blocked = $issues->firstWhere('culprit', 'https://werbung.example');

        $this->assertNotNull($blocked);
        $this->assertSame(
            'Sicherheitsrichtlinie verletzt: script-src blockierte https://werbung.example',
            $blocked->title,
        );
        // Zweimal derselbe Befund, einmal gezählt zwei.
        $this->assertSame(2, $blocked->times_seen);
    }

    public function test_an_expect_ct_report_is_accepted_as_well(): void
    {
        $key = $this->key();

        $this->report($key, ['expect-ct-report' => [
            'date-time' => now()->toIso8601ZuluString(),
            'hostname' => 'shop.example',
            'port' => 443,
            'scheme' => 'https',
            'failure-mode' => 'enforce',
        ]])->assertStatus(201);

        $stored = IngestPayload::query()->sole();

        $this->assertSame('errstack.security/expect-ct', $stored->sdk);

        $issue = Issue::query()->sole();

        $this->assertSame('Certificate Transparency verletzt: shop.example:443', $issue->title);
    }
}
