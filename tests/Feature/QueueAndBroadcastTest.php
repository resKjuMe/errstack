<?php

namespace Tests\Feature;

use App\Enums\QueueName;
use App\Events\DemoIngestProcessed;
use App\Jobs\ProcessDemoIngest;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;
use RuntimeException;
use Tests\TestCase;

class QueueAndBroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_ingest_runs_before_notifications(): void
    {
        $this->assertSame('ingest,notifications,performance,symbolication,default', QueueName::priority());
    }

    public function test_the_worker_call_in_the_dev_script_uses_that_order(): void
    {
        // Sonst laufen Entwicklung und Produktion mit unterschiedlicher Priorität.
        $composer = file_get_contents(base_path('composer.json'));

        $this->assertStringContainsString('--queue='.QueueName::priority(), $composer);
    }

    public function test_the_demo_route_queues_the_job_on_the_ingest_queue(): void
    {
        Queue::fake();

        $this->post('/demo/ingest')->assertRedirect();

        Queue::assertPushedOn(QueueName::Ingest->value, ProcessDemoIngest::class);
    }

    public function test_the_job_broadcasts_its_result(): void
    {
        Event::fake([DemoIngestProcessed::class]);

        (new ProcessDemoIngest('ABC123'))->handle();

        Event::assertDispatched(
            DemoIngestProcessed::class,
            fn (DemoIngestProcessed $event) => $event->reference === 'ABC123'
        );
    }

    public function test_the_broadcast_goes_out_on_the_notifications_queue(): void
    {
        $event = new DemoIngestProcessed('ABC123', 'fertig', '2026-08-06 12:00:00');

        $this->assertSame(QueueName::Notifications->value, $event->broadcastQueue());
        $this->assertSame('demo', $event->broadcastOn()->name);
        $this->assertSame('demo.ingest.processed', $event->broadcastAs());
    }

    public function test_a_failing_job_is_retried_and_then_lands_in_the_failed_jobs_store(): void
    {
        $job = new ProcessDemoIngest('ABC123', shouldFail: true);

        $this->assertSame(3, $job->tries);

        $this->expectException(RuntimeException::class);

        $job->handle();
    }

    public function test_the_schedule_prunes_the_queue_tables(): void
    {
        $commands = collect(Schedule::events())->map(fn ($event) => $event->command);

        $this->assertTrue($commands->contains(fn (?string $c) => str_contains((string) $c, 'queue:prune-failed')));
        $this->assertTrue($commands->contains(fn (?string $c) => str_contains((string) $c, 'queue:prune-batches')));
    }

    public function test_the_shell_only_offers_live_updates_when_broadcasting_is_configured(): void
    {
        // Die Übersicht ist seit der Anmeldung (F3) nur angemeldet zu erreichen
        // und liegt seit U5 unter der Adresse ihrer Organisation.
        $user = User::factory()->create();
        $user->switchOrganization(Organization::factory()->withMember($user)->create());

        $this->actingAs($user);

        config()->set('broadcasting.default', 'null');

        $this->get(route('dashboard'))->assertInertia(fn ($page) => $page->where('shell.broadcast.enabled', false));

        config()->set('broadcasting.default', 'reverb');
        config()->set('broadcasting.connections.reverb.key', 'test-key');

        $this->get(route('dashboard'))->assertInertia(fn ($page) => $page
            ->where('shell.broadcast.enabled', true)
            ->where('shell.broadcast.key', 'test-key')
            ->where('shell.broadcast.channel', 'demo')
        );
    }
}
