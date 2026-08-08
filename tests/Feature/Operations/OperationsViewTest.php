<?php

namespace Tests\Feature\Operations;

use App\Enums\OrganizationRole;
use App\Enums\ProcessingState;
use App\Jobs\ProcessIngestPayload;
use App\Models\IngestPayload;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Die Betriebsansicht — und die Frage, wer sie sehen darf.
 *
 * Sie zeigt Zahlen aus dem Inneren und hat Schaltflächen, die Jobs erneut
 * starten. Beides gehört dem Betreiber der Installation und nicht jedem Konto,
 * das sich anmelden kann.
 */
class OperationsViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_operator_sees_health_backlog_and_what_is_stuck(): void
    {
        Storage::fake('local');

        IngestPayload::factory()->count(2)->create();
        IngestPayload::factory()->create(['processing_state' => ProcessingState::Failed]);

        $this->actingAs($this->operator())
            ->get('/betrieb')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('operations/Index')
                ->where('health.overall', 'ok')
                ->where('backlog.pending', 2)
                ->where('backlog.breaching', false)
                ->where('failedPayloads', 1)
                ->where('failedJobs.total', 0)
            );
    }

    public function test_a_member_without_the_operator_role_is_turned_away(): void
    {
        $user = User::factory()->create();
        Organization::factory()->withMember($user, OrganizationRole::Admin)->create();

        $this->actingAs($user)->get('/betrieb')->assertForbidden();
    }

    public function test_the_operator_list_from_the_environment_wins_over_the_owner_rule(): void
    {
        Storage::fake('local');

        $owner = $this->operator();
        $named = User::factory()->create(['email' => 'betrieb@example.com']);

        config()->set('operations.operators', ['Betrieb@Example.com']);

        // Steht eine Liste, gilt nur sie — ein Besitzer ist auf einer
        // Installation mit mehreren Kunden gerade nicht der Betreiber.
        $this->actingAs($owner)->get('/betrieb')->assertForbidden();

        // Und die Schreibweise der Adresse entscheidet nicht mit.
        $this->actingAs($named)->get('/betrieb')->assertOk();
    }

    public function test_the_menu_entry_stays_hidden_for_everyone_else(): void
    {
        $operator = $this->operator();
        $other = User::factory()->create();

        $this->assertTrue($this->menuHasOperations($operator));
        $this->assertFalse($this->menuHasOperations($other));
    }

    public function test_failed_payloads_can_be_queued_again(): void
    {
        Storage::fake('local');
        Queue::fake();

        $payload = IngestPayload::factory()->create([
            'processing_state' => ProcessingState::Failed,
            'failure' => 'kaputt',
        ]);

        $this->actingAs($this->operator())
            ->post('/betrieb/meldungen/erneut')
            ->assertRedirect();

        Queue::assertPushed(ProcessIngestPayload::class);

        // Erst zurückgestellt, dann eingereiht: ein Arbeiter, der den Job
        // sofort abholt, sähe sonst noch den alten Zustand.
        $this->assertSame(ProcessingState::Pending, $payload->fresh()?->processing_state);
    }

    public function test_a_stranger_cannot_queue_payloads_again(): void
    {
        Queue::fake();

        IngestPayload::factory()->create(['processing_state' => ProcessingState::Failed]);

        $this->actingAs(User::factory()->create())
            ->post('/betrieb/meldungen/erneut')
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_retrying_a_job_that_is_already_gone_says_so(): void
    {
        $this->actingAs($this->operator())
            ->post('/betrieb/jobs/erneut', ['id' => 'gibt-es-nicht'])
            ->assertRedirect()
            ->assertSessionHas('status', __('operations.failed_jobs.gone'));
    }

    /**
     * Ein Konto, das die Ansicht sehen darf: ohne gesetzte Liste ist das der
     * Besitzer einer Organisation.
     */
    private function operator(): User
    {
        $user = User::factory()->create();

        Organization::factory()->withMember($user, OrganizationRole::Owner)->create();

        return $user;
    }

    private function menuHasOperations(User $user): bool
    {
        $shell = $this->actingAs($user)->get('/')->viewData('page')['props']['shell'];

        foreach ($shell['menu'] as $item) {
            if ($item['href'] === route('operations.index')) {
                return true;
            }
        }

        return false;
    }
}
