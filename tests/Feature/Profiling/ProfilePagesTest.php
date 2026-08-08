<?php

namespace Tests\Feature\Profiling;

use App\Models\Organization;
use App\Models\Profile;
use App\Models\Project;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Die Ansichten des Profilings: Liste, Zusammenfassung, Einzelprofil — und die
 * beiden Wege hierher, aus einer Messung und aus einem Ablauf.
 *
 * Die beiden Wege sind ausdrücklich mitgeprüft. Sie sind die Zusage an die
 * Transaktions- und die Trace-Ansicht (PF3, PF4): dort steht eine Kennung, und
 * ein Link darauf muss hier ankommen — auch dann, wenn es zu genau diesem
 * Aufruf kein Profil gibt. Ein Link, der davon abhängt, ob die Stichprobe
 * gerade zugeschlagen hat, wäre keiner.
 */
class ProfilePagesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $organization = Organization::factory()->withMember($this->user)->create();
        $this->project = Project::factory()->for($organization)->create();

        $this->user->switchOrganization($organization);
    }

    /**
     * Die Zeitzone steht immer in der Adresse, damit der aufgelöste Zeitraum
     * nicht von der Einstellung der Anwendung abhängt.
     *
     * @param  array<string, mixed>  $query
     */
    private function url(array $query = []): string
    {
        return '/leistung/profile?'.http_build_query($query + ['tz' => 'UTC']);
    }

    /**
     * Die Nutzlast der Inertia-Seite. Gelesen wie in der Performance-Übersicht:
     * hier werden Zahlen geprüft, und die vergleichen sich als PHP-Werte
     * genauer und lesbarer als über eine Kette von Zusicherungen.
     *
     * @param  TestResponse<Response>  $response
     * @return array<string, mixed>
     */
    private function props(TestResponse $response): array
    {
        $response->assertOk();

        $page = $response->viewData('page');
        $props = is_array($page) ? ($page['props'] ?? []) : [];

        if (! is_array($props)) {
            return [];
        }

        /** @var array<string, mixed> $props */
        return $props;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function transaction(array $attributes = []): Transaction
    {
        return Transaction::factory()->create($attributes + [
            'project_id' => $this->project->id,
            'name' => 'GET /projects',
            'started_at' => now()->subMinute(),
        ]);
    }

    public function test_the_list_shows_the_profiles_of_the_period(): void
    {
        $profile = Profile::factory()->forTransaction($this->transaction())->create();

        $props = $this->props($this->actingAs($this->user)->get($this->url()));

        $this->assertCount(1, $props['profiles']);
        $this->assertSame($profile->id, $props['profiles'][0]['id']);
        // Ohne gewählte Transaktion keine Zusammenfassung: sie wäre der
        // Durchschnitt aus Anmeldeseite und nächtlichem Import.
        $this->assertNull($props['aggregate']);
    }

    public function test_choosing_a_transaction_stacks_its_profiles(): void
    {
        $transaction = $this->transaction();

        Profile::factory()->forTransaction($transaction)
            ->withPaths([[['handle', 'query'], 20_000_000]])
            ->create();

        Profile::factory()->forTransaction($transaction)
            ->withPaths([[['handle', 'query'], 10_000_000]])
            ->create();

        $props = $this->props(
            $this->actingAs($this->user)->get($this->url(['transaction' => 'GET /projects']))
        );

        $flamegraph = $props['aggregate']['flamegraph'];

        // Beide Profile in einem Baum: 30 ms zusammen, und `query` trägt sie
        // ganz — `handle` hat nichts selbst verbraucht.
        $this->assertSame(30_000, $flamegraph['totalUs']);
        $this->assertSame('query', $flamegraph['functions'][0]['function']);
        $this->assertSame(30_000, $flamegraph['functions'][0]['selfUs']);
    }

    public function test_two_releases_are_compared_by_share_of_cpu_time(): void
    {
        $transaction = $this->transaction();

        // Alt: die Hälfte der Rechenzeit in `query`.
        Profile::factory()->forTransaction($transaction)
            ->withPaths([[['handle', 'query'], 10_000_000], [['handle', 'render'], 10_000_000]])
            ->create(['release' => 'errstack@1.0.0']);

        // Neu: alles in `query`.
        Profile::factory()->forTransaction($transaction)
            ->withPaths([[['handle', 'query'], 20_000_000]])
            ->create(['release' => 'errstack@1.1.0']);

        $props = $this->props($this->actingAs($this->user)->get($this->url([
            'transaction' => 'GET /projects',
            'release' => 'errstack@1.1.0',
            'compare' => 'errstack@1.0.0',
        ])));

        $row = $props['comparison'][0];

        $this->assertSame('query', $row['function']);
        $this->assertSame(0.5, $row['baselineShare']);
        $this->assertSame(1.0, $row['candidateShare']);
        $this->assertSame(0.5, $row['deltaShare']);
    }

    public function test_a_single_profile_shows_its_flame_graph(): void
    {
        $transaction = $this->transaction();
        $profile = Profile::factory()->forTransaction($transaction)
            ->withPaths([[['handle', 'query'], 40_000_000]])
            ->create();

        $props = $this->props(
            $this->actingAs($this->user)->get(route('profiling.show', $profile))
        );

        $this->assertSame($profile->id, $props['profile']['id']);
        $this->assertSame(40_000, $props['flamegraph']['totalUs']);
        $this->assertCount(2, $props['frames']);
        // Antwortzeit neben Rechenzeit: klaffen beide auseinander, hat die
        // Anwendung gewartet und nicht gerechnet.
        $this->assertSame($transaction->duration_us, $props['transaction']['durationUs']);
    }

    public function test_a_profile_of_another_organization_stays_hidden(): void
    {
        $foreign = Project::factory()->for(Organization::factory())->create();
        $profile = Profile::factory()
            ->forTransaction(Transaction::factory()->create(['project_id' => $foreign->id]))
            ->create();

        $this->actingAs($this->user)
            ->get(route('profiling.show', $profile))
            ->assertForbidden();
    }

    public function test_the_way_from_a_transaction_leads_to_its_profile(): void
    {
        $transaction = $this->transaction();
        $profile = Profile::factory()->forTransaction($transaction)->create();

        $this->actingAs($this->user)
            ->get(route('profiling.transaction', $transaction))
            ->assertRedirect(route('profiling.show', $profile));
    }

    public function test_a_transaction_without_a_profile_leads_to_the_aggregate(): void
    {
        $transaction = $this->transaction();

        $this->actingAs($this->user)
            ->get(route('profiling.transaction', $transaction))
            ->assertRedirect(route('profiling.index', ['transaction' => $transaction->name]));
    }

    public function test_the_way_from_a_trace_leads_to_a_profile_of_that_trace(): void
    {
        $transaction = $this->transaction();
        $profile = Profile::factory()->forTransaction($transaction)->create();

        $this->actingAs($this->user)
            ->get(route('profiling.trace', $transaction->trace_id))
            ->assertRedirect(route('profiling.show', $profile));
    }

    public function test_a_trace_of_another_organization_leads_nowhere(): void
    {
        $foreign = Project::factory()->for(Organization::factory())->create();
        $transaction = Transaction::factory()->create(['project_id' => $foreign->id]);
        Profile::factory()->forTransaction($transaction)->create();

        // Dieselbe Antwort wie für einen Ablauf ohne Profil: eine geratene
        // Kennung soll nicht verraten, dass es anderswo etwas dazu gibt.
        $this->actingAs($this->user)
            ->get(route('profiling.trace', $transaction->trace_id))
            ->assertRedirect(route('profiling.index'));
    }
}
