<?php

namespace Tests\Feature\Operations;

use App\Enums\BacklogAction;
use App\Enums\ProcessingState;
use App\Models\IngestPayload;
use App\Support\Operations\BacklogWatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Der Wächter über den Rückstand.
 *
 * Der Kern ist nicht die Schwelle, sondern die **Frist** davor: eine Warnung,
 * die bei jeder Lastspitze kommt, wird nach der dritten weggeklickt — und die
 * vierte war die echte. Genauso wenig darf dieselbe Lage minütlich melden.
 */
class BacklogWatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('operations.backlog.max_pending', 10);
        config()->set('operations.backlog.max_age_seconds', 300);
        config()->set('operations.backlog.grace_minutes', 5);
        config()->set('operations.backlog.repeat_minutes', 60);
    }

    public function test_a_backlog_within_the_limits_says_nothing(): void
    {
        IngestPayload::factory()->count(3)->create();

        $this->assertSame(BacklogAction::None, $this->watch()->evaluate()['action']);
    }

    public function test_a_fresh_spike_is_not_worth_waking_anyone(): void
    {
        $this->freezeTime();

        IngestPayload::factory()->count(11)->create();

        $result = $this->watch()->evaluate();

        $this->assertTrue($result['breaching']);
        $this->assertSame(BacklogAction::None, $result['action']);
        $this->assertSame(['pending'], $result['reasons']);
    }

    public function test_a_spike_that_stays_is_reported_once(): void
    {
        $this->freezeTime();

        IngestPayload::factory()->count(11)->create();

        $this->watch()->evaluate();

        $this->travel(6)->minutes();
        $this->assertSame(BacklogAction::Warn, $this->watch()->evaluate()['action']);

        // Und danach Ruhe, bis die Wiederholfrist um ist.
        $this->travel(10)->minutes();
        $this->assertSame(BacklogAction::None, $this->watch()->evaluate()['action']);

        $this->travel(55)->minutes();
        $this->assertSame(BacklogAction::Warn, $this->watch()->evaluate()['action']);
    }

    public function test_a_spike_that_passes_resets_the_grace_period(): void
    {
        $this->freezeTime();

        $payloads = IngestPayload::factory()->count(11)->create();

        $this->watch()->evaluate();

        // Vier Minuten später ist der Ansturm abgearbeitet — es wurde nie
        // gewarnt, also gibt es auch nichts zu entwarnen.
        $this->travel(4)->minutes();
        $payloads->each(self::markProcessed(...));

        $this->assertSame(BacklogAction::None, $this->watch()->evaluate()['action']);

        // Und die Frist beginnt beim nächsten Mal von vorn.
        IngestPayload::factory()->count(11)->create();

        $this->assertSame(BacklogAction::None, $this->watch()->evaluate()['action']);

        $this->travel(6)->minutes();
        $this->assertSame(BacklogAction::Warn, $this->watch()->evaluate()['action']);
    }

    public function test_a_reported_backlog_that_clears_is_followed_by_an_all_clear(): void
    {
        $this->freezeTime();

        $payloads = IngestPayload::factory()->count(11)->create();

        $this->watch()->evaluate();
        $this->travel(6)->minutes();
        $this->assertSame(BacklogAction::Warn, $this->watch()->evaluate()['action']);

        $payloads->each(self::markProcessed(...));

        $this->assertSame(BacklogAction::Recover, $this->watch()->evaluate()['action']);

        // Genau einmal — die Entwarnung wiederholt sich nicht.
        $this->assertSame(BacklogAction::None, $this->watch()->evaluate()['action']);
    }

    public function test_a_single_old_payload_is_enough(): void
    {
        $this->freezeTime();

        // Eine einzige Meldung, aber sie liegt seit einer Stunde. Die Menge
        // allein bliebe hier still.
        IngestPayload::factory()->create(['created_at' => now()->subHour()]);

        $result = $this->watch()->evaluate();

        $this->assertTrue($result['breaching']);
        $this->assertSame(['age'], $result['reasons']);
    }

    public function test_the_command_writes_the_warning_to_the_log(): void
    {
        $this->freezeTime();

        IngestPayload::factory()->count(11)->create();

        $this->artisan('ops:watch')->assertSuccessful();

        $this->travel(6)->minutes();

        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('warning')->once()->withArgs(
            fn (string $message): bool => str_contains($message, 'hängt hinterher'),
        );

        $this->artisan('ops:watch')->assertSuccessful();
    }

    /**
     * Die Ansicht darf den Zustand nicht fortschreiben — sonst entschiede das
     * Aufrufen einer Seite darüber, wann gewarnt wird.
     */
    public function test_looking_at_the_status_does_not_start_the_clock(): void
    {
        $this->freezeTime();

        IngestPayload::factory()->count(11)->create();

        $this->watch()->status();

        $this->travel(6)->minutes();

        // Hätte `status()` die Uhr gestartet, käme hier bereits die Warnung.
        $this->assertSame(BacklogAction::None, $this->watch()->evaluate()['action']);
    }

    /**
     * Eine Meldung als ausgewertet abhaken.
     *
     * Von Hand gesetzt statt per `update()`: das Modell hat bewusst kein
     * `Fillable` — an ihm wird nur über seine eigenen Methoden geschrieben, und
     * die Verarbeitungsspalten setzt sonst die Kette selbst.
     */
    private static function markProcessed(IngestPayload $payload): void
    {
        $payload->processing_state = ProcessingState::Processed;
        $payload->processed_at = now();
        $payload->save();
    }

    private function watch(): BacklogWatch
    {
        return $this->app->make(BacklogWatch::class);
    }
}
