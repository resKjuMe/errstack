<?php

namespace Tests\Feature\Ingest;

use App\Enums\DiscardReason;
use App\Models\IngestDiscard;
use App\Models\IngestPayload;
use App\Models\IngestVolume;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectKey;
use App\Models\SpikeProtectionState;
use App\Models\User;
use App\Support\Ingest\Spikes\SpikeBaseline;
use App\Support\Ingest\Spikes\SpikeGuard;
use App\Support\Ingest\Spikes\SpikeSweep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Der Ausschlag-Schutz an der Aufnahme (A7).
 *
 * Die vier Zusagen dieser Prüfung sind die vier Zusagen des Features:
 * erkannt wird am **Verlauf** und nicht an einem festen Wert, Verworfenes wird
 * **gezählt**, das Team wird bei Anfang **und** Ende **benachrichtigt**, und
 * die Drosselung lässt sich **von Hand aufheben**.
 *
 * Die Zeit steht fest: der Schutz rechnet in Minutenfenstern, und ein Test, der
 * an einem Minutenwechsel anders ausgeht, ist keiner.
 */
class SpikeProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-03-10 12:30:30', 'UTC'));

        // Die Zustellung selbst gehört zu A1; hier zählt, **dass** etwas
        // eingereiht wird.
        Queue::fake();
    }

    public function test_a_flood_is_throttled_once_the_history_says_it_is_unusual(): void
    {
        [$key, $project] = $this->project();
        $this->history($project, 2);

        // Zwei Meldungen je Minute im Verlauf, Faktor 5, Untergrenze 10: die
        // Schwelle ist die Untergrenze, weil das Fünffache von zwei darunter
        // liegt.
        $this->send($key, 10);

        $this->assertSame(10, IngestPayload::query()->count());
        $this->assertNull(SpikeProtectionState::open($project));

        $this->send($key, 5);

        // Ab der elften Meldung wird verworfen — abgelegt sind weiterhin zehn.
        $this->assertSame(10, IngestPayload::query()->count());
        $this->assertNotNull(SpikeProtectionState::open($project));
    }

    /**
     * Der Kern der Zusage: gedrosselte Ereignisse verschwinden nicht, sie
     * werden gezählt. Ohne diesen Nachweis wäre die Drosselung ein
     * stillschweigender Datenverlust.
     */
    public function test_throttled_events_are_counted_and_shown(): void
    {
        [$key, $project] = $this->project();
        $this->history($project, 2);

        $this->send($key, 15);

        // Erst der Durchlauf schreibt die Zahlen fest — währenddessen zählt der
        // Schutz im Zwischenspeicher, weil ein Schreibvorgang je verworfener
        // Meldung genau der Schreibsturm wäre, den er verhindern soll.
        $this->sweep();

        $discarded = IngestDiscard::query()
            ->where('project_id', $project->id)
            ->where('reason', DiscardReason::Throttled->value)
            ->sum('quantity');

        $this->assertSame(5, (int) $discarded);
        $this->assertSame(5, SpikeProtectionState::query()->firstOrFail()->discarded);
    }

    /**
     * Der Verlauf zählt **alle** gemeldeten Ereignisse, auch die verworfenen —
     * sonst sähe eine laufende Drosselung aus wie eine Anwendung, die sich
     * beruhigt hat.
     */
    public function test_the_history_records_what_was_reported_not_what_was_kept(): void
    {
        [$key, $project] = $this->project();
        $this->history($project, 2);

        $this->send($key, 15);
        $this->sweep();

        $minute = IngestVolume::query()
            ->where('project_id', $project->id)
            ->where('bucket', Carbon::now()->startOfMinute()->subMinute())
            ->firstOrFail();

        $this->assertSame(15, $minute->quantity);
        $this->assertTrue($minute->throttled);
    }

    public function test_the_team_is_told_when_it_triggers_and_when_it_ends(): void
    {
        [$key, $project, $organization] = $this->project();
        NotificationChannel::factory()->for($organization)->create();
        $this->history($project, 2);

        $this->send($key, 15);

        $this->assertSame(1, NotificationDelivery::query()->count());

        // Die Minute der Flut selbst — sie ist alles andere als ruhig.
        $this->sweep();
        $this->assertNotNull(SpikeProtectionState::open($project));

        // Zwei ruhige Minuten beenden die Drosselung, eine genügt nicht: die
        // erste Minute nach einer Flut ist oft die, in der das SDK der
        // meldenden Anwendung gerade seinen eigenen Wartezustand abwartet.
        $this->sweep();
        $this->assertNotNull(SpikeProtectionState::open($project));
        $this->assertSame(1, NotificationDelivery::query()->count());

        $this->sweep();
        $this->assertNull(SpikeProtectionState::open($project));
        $this->assertSame(2, NotificationDelivery::query()->count());
    }

    public function test_a_throttle_can_be_lifted_by_hand_and_stays_lifted(): void
    {
        [$key, $project] = $this->project(['spike_release_minutes' => 30]);
        $this->history($project, 2);

        $this->send($key, 15);
        $state = SpikeProtectionState::open($project);
        $this->assertNotNull($state);

        app(SpikeGuard::class)->release($project, User::factory()->create());

        $this->assertNull(SpikeProtectionState::open($project));
        $this->assertNotNull($state->fresh()->released_at);

        // Die angebrochene Minute gehört noch in die Bilanz der eben
        // geschlossenen Drosselung — sonst fielen die letzten Sekunden aus ihrer
        // eigenen Abrechnung.
        $this->sweep();
        $this->assertSame(5, $state->fresh()->discarded);

        // Und die Flut läuft weiter: ohne Ruhefrist wäre der Knopf wirkungslos,
        // weil die nächste Minute sofort wieder auslösen würde.
        $this->send($key, 50);

        $this->assertNull(SpikeProtectionState::open($project));
        $this->assertSame(60, IngestPayload::query()->count());
    }

    public function test_nothing_is_throttled_while_the_protection_is_off(): void
    {
        [$key, $project] = $this->project(['spike_protection_enabled' => false]);
        $this->history($project, 2);

        $this->send($key, 50);

        $this->assertSame(50, IngestPayload::query()->count());
        $this->assertNull(SpikeProtectionState::open($project));
    }

    /**
     * Ohne genug Verlauf entscheidet der Schutz bewusst nicht: ein
     * Vergleichswert aus zwei Minuten ist keine Aussage über den Normalbetrieb,
     * und die dritte Minute stünde schon in der Drosselung.
     */
    public function test_a_fresh_project_is_never_throttled(): void
    {
        [$key, $project] = $this->project();

        $this->send($key, 50);

        $this->assertSame(50, IngestPayload::query()->count());
        $this->assertNull(SpikeProtectionState::open($project));
    }

    /**
     * Gedrosselte Minuten bleiben beim Vergleichswert außen vor — sonst hübe
     * eine lange Spitze ihren eigenen Maßstab an, bis sie als normal gilt.
     */
    public function test_throttled_minutes_do_not_raise_the_baseline(): void
    {
        [$key, $project] = $this->project();
        $this->history($project, 2);

        IngestVolume::factory()->for($project)->throttled()->count(30)->sequence(
            fn ($sequence): array => [
                'bucket' => Carbon::now()->startOfMinute()->subMinutes(200 + $sequence->index),
                'quantity' => 100_000,
            ],
        )->create();

        $this->assertSame(2.0, SpikeBaseline::for($project)->baseline);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{ProjectKey, Project, Organization}
     */
    private function project(array $settings = []): array
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create($settings + [
            'spike_protection_enabled' => true,
            'spike_threshold_factor' => 5,
            'spike_minimum_events' => 10,
            'spike_release_minutes' => 0,
        ]);

        return [$project->keys()->firstOrFail(), $project, $organization];
    }

    /**
     * Ein unauffälliger Verlauf: genug Minuten, damit der Schutz überhaupt
     * entscheidet, alle mit derselben Menge.
     */
    private function history(Project $project, int $perMinute): void
    {
        IngestVolume::factory()->for($project)->count(SpikeBaseline::MINIMUM_SAMPLES + 5)->sequence(
            fn ($sequence): array => [
                'bucket' => Carbon::now()->startOfMinute()->subMinutes($sequence->index + 2),
                'quantity' => $perMinute,
            ],
        )->create();
    }

    /**
     * Eine Minute weiter und der Durchlauf darüber.
     *
     * Erst die Uhr, dann der Durchlauf: verbucht wird die **abgeschlossene**
     * Minute, in der laufenden wird noch gezählt.
     */
    private function sweep(): void
    {
        Carbon::setTestNow(Carbon::now()->addMinute());

        app(SpikeSweep::class)->run();
    }

    private function send(ProjectKey $key, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->call(
                'POST',
                "/api/{$key->project_id}/store/",
                server: $this->transformHeadersToServerVars([
                    'Content-Type' => 'application/json',
                    'X-Sentry-Auth' => "Sentry sentry_version=7, sentry_key={$key->public_key}",
                ]),
                content: (string) json_encode([
                    'timestamp' => Carbon::now()->toIso8601String(),
                    'platform' => 'php',
                    'level' => 'error',
                    'message' => 'Etwas ist kaputt.',
                ]),
            )->assertStatus(200);
        }
    }
}
