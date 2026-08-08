<?php

namespace Tests\Feature\Operations;

use App\Models\IngestPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `/metrics` — dieselben Zahlen wie in der Betriebsansicht, nur für Maschinen.
 *
 * Der wichtigste Fall steht zuerst: die Adresse ist **aus**, solange niemand
 * sie einschaltet. Sie nennt Rückstände und Laufzeiten und sagt damit einem
 * Fremden, wann diese Installation überlastet ist.
 */
class MetricsEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_endpoint_is_off_by_default(): void
    {
        $this->get('/metrics')->assertNotFound();
    }

    public function test_a_switched_off_endpoint_does_not_admit_that_it_exists(): void
    {
        // `404` und nicht `403`: eine abgeschaltete Adresse soll nicht
        // verraten, dass es sie gibt.
        config()->set('operations.metrics.token', 'geheim');

        $this->get('/metrics')->assertNotFound();
    }

    public function test_it_serves_the_prometheus_text_format(): void
    {
        Storage::fake('local');
        config()->set('operations.metrics.enabled', true);

        $this->pendingPayloads(3);

        $response = $this->get('/metrics');

        $response->assertOk();
        $this->assertStringContainsString(
            'version=0.0.4',
            (string) $response->headers->get('Content-Type'),
        );

        $body = (string) $response->getContent();

        $this->assertStringContainsString('# TYPE errstack_ingest_backlog gauge', $body);
        $this->assertStringContainsString("\nerrstack_ingest_backlog 3\n", $body);
        $this->assertStringContainsString('errstack_ingest_payloads{state="pending"} 3', $body);
        $this->assertStringContainsString('errstack_queue_size{queue="ingest"}', $body);
        $this->assertStringContainsString('errstack_health{check="database"} 1', $body);
        $this->assertStringContainsString('errstack_failed_jobs 0', $body);
    }

    public function test_a_configured_token_is_required(): void
    {
        Storage::fake('local');
        config()->set('operations.metrics.enabled', true);
        config()->set('operations.metrics.token', 'geheim');

        $this->get('/metrics')->assertForbidden();
        $this->withToken('falsch')->get('/metrics')->assertForbidden();
        $this->withToken('geheim')->get('/metrics')->assertOk();
    }

    public function test_the_prefix_can_be_changed(): void
    {
        Storage::fake('local');
        config()->set('operations.metrics.enabled', true);
        config()->set('operations.metrics.prefix', 'zweiteinstallation');

        $body = (string) $this->get('/metrics')->getContent();

        $this->assertStringContainsString('zweiteinstallation_ingest_backlog 0', $body);
        $this->assertStringNotContainsString('errstack_ingest_backlog', $body);
    }

    private function pendingPayloads(int $count): void
    {
        IngestPayload::factory()->count($count)->create();
    }
}
