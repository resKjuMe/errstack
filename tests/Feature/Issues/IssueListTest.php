<?php

namespace Tests\Feature\Issues;

use App\Enums\CountPeriod;
use App\Enums\IssuePriority;
use App\Enums\IssueStatus;
use App\Models\Environment;
use App\Models\Issue;
use App\Models\IssueCount;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Die Fehlerliste: was drinsteht, in welcher Reihenfolge und wer sie sehen darf.
 */
class IssueListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Ein fester Zeitpunkt: die Liste filtert über einen relativen Zeitraum,
        // und ein Test, der um Mitternacht anders ausgeht, ist keiner.
        Carbon::setTestNow(CarbonImmutable::parse('2026-03-10 12:00:00', 'UTC'));
    }

    /**
     * @return array{User, Organization, Project}
     */
    private function context(): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user)->create();
        $project = Project::factory()->for($organization)->create(['name' => 'Webshop', 'slug' => 'webshop']);

        $user->switchOrganization($organization);

        return [$user, $organization, $project];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function issue(Project $project, string $title, array $attributes = []): Issue
    {
        return Issue::factory()->for($project)->create([
            'title' => $title,
            'first_seen' => Carbon::now()->subHours(6),
            'last_seen' => Carbon::now()->subHours(1),
            ...$attributes,
        ]);
    }

    public function test_the_list_shows_counters_users_and_times(): void
    {
        [$user, , $project] = $this->context();

        $this->issue($project, 'RuntimeException: Zahlung fehlgeschlagen', [
            'times_seen' => 1234,
            'users_seen' => 56,
        ]);

        $this->actingAs($user)
            ->get(route('issues.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('issues/Index')
                ->has('issues.data', 1)
                ->where('issues.data.0.title', 'RuntimeException: Zahlung fehlgeschlagen')
                ->where('issues.data.0.timesSeen', 1234)
                ->where('issues.data.0.usersSeen', 56)
                ->where('issues.total', 1)
            );
    }

    public function test_sorting_by_frequency_puts_the_loudest_first(): void
    {
        [$user, , $project] = $this->context();

        $this->issue($project, 'Selten', ['times_seen' => 3]);
        $this->issue($project, 'Oft', ['times_seen' => 900]);
        $this->issue($project, 'Mittel', ['times_seen' => 40]);

        $this->actingAs($user)
            ->get(route('issues.index', ['sort' => 'times_seen']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('issues.data.0.title', 'Oft')
                ->where('issues.data.1.title', 'Mittel')
                ->where('issues.data.2.title', 'Selten')
            );
    }

    public function test_sorting_by_priority_uses_the_rank_and_not_the_alphabet(): void
    {
        [$user, , $project] = $this->context();

        // Alphabetisch stünde „high" vor „low" vor „medium" — die mittlere Stufe
        // also zuletzt. Genau das darf nicht passieren.
        $this->issue($project, 'Niedrig', ['priority' => IssuePriority::Low]);
        $this->issue($project, 'Hoch', ['priority' => IssuePriority::High]);
        $this->issue($project, 'Mittel', ['priority' => IssuePriority::Medium]);

        $this->actingAs($user)
            ->get(route('issues.index', ['sort' => 'priority']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('issues.data.0.title', 'Hoch')
                ->where('issues.data.1.title', 'Mittel')
                ->where('issues.data.2.title', 'Niedrig')
            );
    }

    public function test_sorting_by_first_and_last_occurrence(): void
    {
        [$user, , $project] = $this->context();

        $this->issue($project, 'Alt', [
            'first_seen' => Carbon::now()->subDays(20),
            'last_seen' => Carbon::now()->subMinutes(5),
        ]);
        $this->issue($project, 'Neu', [
            'first_seen' => Carbon::now()->subHours(2),
            'last_seen' => Carbon::now()->subHours(2),
        ]);

        $this->actingAs($user)
            ->get(route('issues.index', ['sort' => 'last_seen', 'period' => '30d']))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('issues.data.0.title', 'Alt'));

        $this->actingAs($user)
            ->get(route('issues.index', ['sort' => 'first_seen', 'period' => '30d']))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('issues.data.0.title', 'Neu'));
    }

    public function test_only_open_issues_show_up_until_the_state_is_widened(): void
    {
        [$user, , $project] = $this->context();

        $this->issue($project, 'Offen');
        $this->issue($project, 'Erledigt', ['status' => IssueStatus::Resolved]);

        $this->actingAs($user)
            ->get(route('issues.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues.data', 1)
                ->where('issues.data.0.title', 'Offen')
            );

        $this->actingAs($user)
            ->get(route('issues.index', ['status' => 'alle']))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('issues.data', 2));
    }

    /**
     * Der Zeitraum meint die **Spanne** eines Eintrags und nicht seinen letzten
     * Zeitpunkt: ein Fehler, der vor drei Wochen anfing und vor einer Stunde
     * wieder auftrat, gehört in „letzte 24 Stunden".
     */
    public function test_the_period_asks_whether_the_issue_occurred_within_it(): void
    {
        [$user, , $project] = $this->context();

        $this->issue($project, 'Läuft noch', [
            'first_seen' => Carbon::now()->subDays(21),
            'last_seen' => Carbon::now()->subHour(),
        ]);
        $this->issue($project, 'Vorbei', [
            'first_seen' => Carbon::now()->subDays(21),
            'last_seen' => Carbon::now()->subDays(20),
        ]);

        $this->actingAs($user)
            ->get(route('issues.index', ['period' => '24h']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues.data', 1)
                ->where('issues.data.0.title', 'Läuft noch')
            );
    }

    public function test_the_trend_comes_from_the_counters_and_shares_one_grid(): void
    {
        [$user, , $project] = $this->context();

        $issue = $this->issue($project, 'Mit Verlauf');

        // Zwei Fenster innerhalb der letzten 24 Stunden, dazwischen Stille.
        foreach ([[3, 7], [1, 2]] as [$hoursAgo, $count]) {
            IssueCount::query()->create([
                'issue_id' => $issue->id,
                'period' => CountPeriod::Hour,
                'window_start' => CarbonImmutable::now()->subHours($hoursAgo)->startOfHour(),
                'event_count' => $count,
            ]);
        }

        $this->actingAs($user)
            ->get(route('issues.index', ['period' => '24h']))
            ->assertInertia(function (AssertableInertia $page) {
                /** @var list<int> $series */
                $series = $page->prop('issues.data.0.series');

                // Ein Raster über den ganzen Zeitraum und nicht nur über die
                // Fenster mit Zahlen: die stillen Stunden sind Nullen.
                $this->assertGreaterThan(2, count($series));
                $this->assertSame([7, 2], array_values(array_filter($series)));
            });
    }

    /**
     * Die Zusage „auch bei 100.000 Einträgen flott" steht und fällt damit, dass
     * eine Seite eine feste Zahl von Abfragen kostet — und nicht eine je Zeile.
     *
     * Der Test vergleicht deshalb nicht gegen eine Wunschzahl, sondern gegen sich
     * selbst: dieselbe Seite mit zehnmal so vielen Einträgen darf keine einzige
     * Abfrage mehr brauchen. Ein Zugriff auf das Projekt oder die Zeitreihe
     * innerhalb der Schleife fiele hier sofort auf, und zwar bevor er im Betrieb
     * auffällt.
     */
    public function test_a_page_costs_the_same_number_of_queries_regardless_of_its_length(): void
    {
        [$user, , $project] = $this->context();
        $this->actingAs($user);

        $this->issue($project, 'Einer');
        $few = $this->countQueries();

        for ($i = 0; $i < 25; $i++) {
            $this->issue($project, 'Nummer '.$i);
        }

        $this->assertSame($few, $this->countQueries());
    }

    private function countQueries(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get(route('issues.index'))->assertOk();

        $count = count(DB::getQueryLog());

        DB::disableQueryLog();

        return $count;
    }

    public function test_the_list_stops_at_the_organization_of_the_viewer(): void
    {
        [$user] = $this->context();

        $foreign = Project::factory()->for(Organization::factory())->create();
        $this->issue($foreign, 'Fremd');

        $this->actingAs($user)
            ->get(route('issues.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('issues.data', 0));
    }

    public function test_the_page_says_that_the_environment_does_not_narrow_the_list(): void
    {
        [$user, , $project] = $this->context();

        Environment::factory()->for($project)->create(['name' => 'production']);
        $this->issue($project, 'Egal welche Umgebung');

        $this->actingAs($user)
            ->get(route('issues.index', ['environment' => 'production']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('environmentIgnored', true)
                ->has('issues.data', 1)
            );
    }

    public function test_the_list_needs_a_signed_in_viewer(): void
    {
        $this->get(route('issues.index'))->assertRedirect(route('login'));
    }
}
