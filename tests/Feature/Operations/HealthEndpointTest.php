<?php

namespace Tests\Feature\Operations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * `/health` — die Auskunft, auf die sich ein Ladeverteiler verlässt.
 *
 * Zwei Dinge müssen stimmen, und beide sind leicht zu verlieren: der
 * **Statuscode**, weil nichts anderes gelesen wird, und die **Wortkargheit**,
 * weil die Adresse offen steht. Eine Antwort, die den Grund nennt, nennt bei
 * einer Datenbank auch deren Rechnernamen.
 */
class HealthEndpointTest extends TestCase
{
    use RefreshDatabase;

    /** Die Fehlermeldung, die auf keinen Fall nach draußen darf. */
    private const SECRET = 'Connection refused: ablage.internal';

    public function test_a_healthy_installation_answers_ok_without_signing_in(): void
    {
        Storage::fake('local');

        $response = $this->getJson('/health');

        $response->assertOk();
        $response->assertExactJson([
            'status' => 'ok',
            'checks' => [
                'database' => 'ok',
                'cache' => 'ok',
                'queue' => 'ok',
                'storage' => 'ok',
            ],
        ]);
    }

    public function test_a_broken_component_answers_service_unavailable(): void
    {
        $this->breakStorage();

        $response = $this->getJson('/health');

        $response->assertStatus(503);
        $response->assertJsonPath('status', 'failed');
        $response->assertJsonPath('checks.storage', 'failed');

        // Und die übrigen Prüfungen laufen weiter: eine gescheiterte darf die
        // Antwort nicht abbrechen, sonst steht im Ernstfall nur der erste
        // Fehler da und nicht das Bild.
        $response->assertJsonPath('checks.database', 'ok');
    }

    public function test_the_answer_says_nothing_about_the_inside(): void
    {
        $this->breakStorage();

        $body = (string) $this->getJson('/health')->getContent();

        $this->assertStringNotContainsString('ablage.internal', $body);
        $this->assertStringNotContainsString('Connection refused', $body);

        // Und keine Zahlen: die Laufzeit einer Prüfung verrät, wie eine
        // Installation gerade dasteht. Sie steht in der Betriebsansicht.
        $this->assertStringNotContainsString('duration', $body);
    }

    public function test_the_answer_is_never_cached(): void
    {
        Storage::fake('local');

        $response = $this->getJson('/health');

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    /**
     * Lässt die Dateiablage scheitern — mit einer Meldung, die verrät, wo sie
     * steht. Genau die darf in der Antwort nicht auftauchen.
     */
    private function breakStorage(): void
    {
        Storage::shouldReceive('disk')
            ->andThrow(new RuntimeException(self::SECRET));
    }
}
