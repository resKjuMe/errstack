<?php

namespace Tests\Feature\Filters;

use App\Enums\FilterPeriod;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Support\Filters\GlobalFilter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class GlobalFilterTest extends TestCase
{
    use RefreshDatabase;

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
     * @param  array<string, mixed>  $input
     */
    private function filter(User $user, array $input = []): GlobalFilter
    {
        return GlobalFilter::resolve($user->resolveCurrentOrganization(), $user, $input);
    }

    public function test_without_a_selection_every_project_of_the_organization_counts(): void
    {
        [$user, $organization] = $this->context();
        Project::factory()->for($organization)->create(['slug' => 'blog']);

        $filter = $this->filter($user);

        $this->assertCount(2, $filter->projects);
        $this->assertSame([], $filter->selectedSlugs);
    }

    public function test_only_the_chosen_projects_count(): void
    {
        [$user, $organization] = $this->context();
        Project::factory()->for($organization)->create(['slug' => 'blog']);

        $filter = $this->filter($user, ['projects' => ['blog']]);

        $this->assertSame(['blog'], $filter->projects->pluck('slug')->all());
    }

    public function test_a_project_of_another_organization_is_ignored(): void
    {
        [$user] = $this->context();
        $foreign = Project::factory()->create(['slug' => 'fremd']);

        $filter = $this->filter($user, ['projects' => ['fremd', 'webshop']]);

        $this->assertSame(['webshop'], $filter->projects->pluck('slug')->all());
        $this->assertFalse($filter->projects->contains('id', $foreign->id));
    }

    public function test_the_default_period_covers_the_last_24_hours(): void
    {
        [$user] = $this->context();
        Carbon::setTestNow('2026-08-07 12:00:00');
        CarbonImmutable::setTestNow('2026-08-07 12:00:00');

        $filter = $this->filter($user, ['tz' => 'UTC']);

        $this->assertSame(FilterPeriod::Last24Hours, $filter->period);
        $this->assertSame('2026-08-06 12:00', $filter->from->format('Y-m-d H:i'));
        $this->assertSame('2026-08-07 12:00', $filter->to->format('Y-m-d H:i'));
    }

    public function test_a_relative_period_is_resolved_in_the_timezone_of_the_viewer(): void
    {
        [$user] = $this->context();
        CarbonImmutable::setTestNow('2026-08-07 22:30:00');

        $berlin = $this->filter($user, ['period' => '7d', 'tz' => 'Europe/Berlin']);
        $tokyo = $this->filter($user, ['period' => '7d', 'tz' => 'Asia/Tokyo']);

        // Derselbe Zeitpunkt, verschiedene Uhren: die Grenzen zeigen die Ortszeit,
        // gefragt wird die Datenbank in beiden Fällen nach demselben Moment.
        $this->assertSame('Europe/Berlin', $berlin->timezone);
        $this->assertSame('2026-08-08 00:30', $berlin->to->format('Y-m-d H:i'));
        $this->assertSame('2026-08-08 07:30', $tokyo->to->format('Y-m-d H:i'));
        $this->assertTrue($berlin->toUtc()->equalTo($tokyo->toUtc()));
    }

    public function test_an_own_period_uses_whole_days_of_the_viewers_timezone(): void
    {
        [$user] = $this->context();

        $filter = $this->filter($user, [
            'period' => 'custom',
            'from' => '2026-08-01',
            'to' => '2026-08-05',
            'tz' => 'Europe/Berlin',
        ]);

        $this->assertSame(FilterPeriod::Custom, $filter->period);
        $this->assertSame('2026-08-01 00:00:00', $filter->from->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-05 23:59:59', $filter->to->format('Y-m-d H:i:s'));
        // 00:00 Berliner Zeit ist im Sommer 22:00 des Vortags in UTC.
        $this->assertSame('2026-07-31 22:00:00', $filter->fromUtc()->format('Y-m-d H:i:s'));
    }

    public function test_an_own_period_without_dates_falls_back_to_the_default(): void
    {
        [$user] = $this->context();

        $filter = $this->filter($user, ['period' => 'custom']);

        $this->assertSame(FilterPeriod::default(), $filter->period);
    }

    public function test_an_unknown_timezone_does_not_break_the_filter(): void
    {
        [$user] = $this->context();

        $filter = $this->filter($user, ['tz' => 'Mars/Olympus']);

        $this->assertSame(config('app.timezone'), $filter->timezone);
    }

    public function test_only_visible_environments_are_offered(): void
    {
        [$user, , $project] = $this->context();
        Environment::factory()->for($project)->create(['name' => 'production']);
        Environment::factory()->for($project)->hidden()->create(['name' => 'staging']);

        $filter = $this->filter($user);

        $this->assertSame(['production'], $filter->availableEnvironments);
    }

    public function test_a_hidden_environment_cannot_be_selected(): void
    {
        [$user, , $project] = $this->context();
        Environment::factory()->for($project)->hidden()->create(['name' => 'staging']);

        $filter = $this->filter($user, ['environment' => 'staging']);

        $this->assertNull($filter->environment);
    }

    public function test_the_form_values_mirror_the_selection(): void
    {
        [$user, , $project] = $this->context();
        Environment::factory()->for($project)->create(['name' => 'production']);

        $filter = $this->filter($user, [
            'projects' => ['webshop'],
            'environment' => 'production',
            'period' => '7d',
            'tz' => 'Europe/Berlin',
        ]);

        $values = $filter->formValues();

        $this->assertSame(['webshop'], $values['projects']);
        $this->assertSame('production', $values['environment']);
        $this->assertSame('7d', $values['period']);
        $this->assertSame('Europe/Berlin', $values['tz']);
    }

    public function test_the_filter_restricts_a_query_to_projects_environment_and_range(): void
    {
        [$user, $organization, $project] = $this->context();
        $other = Project::factory()->for($organization)->create(['slug' => 'blog']);

        // Ohne Ereignis-Tabellen (die kommen mit Phase P1) steht hier die
        // Umgebungs-Tabelle für die Auswertung: sie hat Projekt, Name und einen
        // Zeitstempel und zeigt damit dasselbe Verhalten.
        Environment::factory()->for($project)->create([
            'name' => 'production',
            'last_seen_at' => Carbon::now()->subHour(),
        ]);
        Environment::factory()->for($project)->create([
            // Andere Umgebung, im Zeitraum — darf nicht mitkommen.
            'name' => 'staging',
            'last_seen_at' => Carbon::now()->subHour(),
        ]);
        Environment::factory()->for($project)->create([
            // Richtige Umgebung, aber außerhalb des Zeitraums.
            'name' => 'preview',
            'last_seen_at' => Carbon::now()->subDays(3),
        ]);
        Environment::factory()->for($other)->create([
            // Richtige Umgebung im Zeitraum, aber anderes Projekt.
            'name' => 'production',
            'last_seen_at' => Carbon::now()->subHour(),
        ]);

        $filter = $this->filter($user, ['projects' => ['webshop'], 'environment' => 'production', 'tz' => 'UTC']);

        $found = $filter->apply(Environment::query(), 'last_seen_at', 'project_id', 'name')->get();

        $this->assertCount(1, $found);
        $this->assertSame($project->id, $found->first()->project_id);
        $this->assertSame('production', $found->first()->name);
    }

    public function test_a_range_outside_the_data_finds_nothing(): void
    {
        [$user, , $project] = $this->context();
        Environment::factory()->for($project)->create([
            'name' => 'production',
            'last_seen_at' => Carbon::parse('2020-01-01 12:00'),
        ]);

        $filter = $this->filter($user, ['tz' => 'UTC']);

        $this->assertSame(0, $filter->apply(Environment::query(), 'last_seen_at', 'project_id', 'name')->count());
    }

    public function test_the_dashboard_ships_the_filter_and_keeps_the_selection_after_a_reload(): void
    {
        [$user, $organization, $project] = $this->context();
        Environment::factory()->for($project)->create(['name' => 'production']);

        $url = route('dashboard', $organization).'?projects[]=webshop&environment=production&period=7d&tz=Europe%2FBerlin';

        $this->actingAs($user)->get($url)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->where('filter.value.projects', ['webshop'])
                ->where('filter.value.environment', 'production')
                ->where('filter.value.period', '7d')
                ->where('filter.timezone', 'Europe/Berlin')
                ->where('scope.projects', ['Webshop'])
                ->has('filter.environmentOptions', 1)
                ->has('filter.periodOptions', count(FilterPeriod::cases()))
            );

        // Derselbe Link ein zweites Mal — ein geteilter Link zeigt dieselbe
        // Auswahl, ohne dass irgendwo ein Zustand hängen bleibt.
        $this->actingAs($user)->get($url)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filter.value.projects', ['webshop'])
                ->where('filter.value.period', '7d')
            );
    }

    public function test_the_dashboard_rejects_a_reversed_own_period(): void
    {
        [$user, $organization] = $this->context();

        $this->actingAs($user)
            ->get(route('dashboard', $organization).'?period=custom&from=2026-08-05&to=2026-08-01')
            ->assertSessionHasErrors('to');
    }

    public function test_without_an_organization_the_filter_offers_nothing(): void
    {
        $user = User::factory()->create();

        $filter = $this->filter($user);

        $this->assertCount(0, $filter->availableProjects);
        $this->assertSame([], $filter->projectIds());
        $this->assertSame(0, $filter->apply(Environment::query(), 'last_seen_at')->count());
    }

    /**
     * Die Nutzlast der Leiste kommt vom Rahmen und nicht von der Seite: sie liegt
     * an jeder Auswertungsseite an, ohne dass deren Controller sie mitgibt.
     */
    public function test_every_analysis_page_carries_the_filter_payload(): void
    {
        [$user, $organization, $project] = $this->context();
        Environment::factory()->for($project)->create(['name' => 'production']);

        // Über die Routen-Namen und nicht über die alten Wurzelpfade: die
        // Fachseiten liegen seit U5 unter `/organisationen/{organisation}/…`,
        // und `/versionen` beantwortet eine Weiterleitung statt der Seite.
        $names = ['releases.index', 'tags.index', 'performance.index', 'feedback.index'];

        foreach ($names as $name) {
            $this->actingAs($user)
                ->get(route($name, $organization).'?projects[]=webshop&environment=production&period=7d')
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('filter.value.projects', ['webshop'])
                    ->where('filter.value.environment', 'production')
                    ->where('filter.value.period', '7d')
                );
        }
    }

    /**
     * Umgekehrt: wo es nichts auszuwerten gibt, gibt es auch keine Leiste. Das
     * `null` ist das Zeichen, an dem der Rahmen sie weglässt.
     */
    public function test_pages_without_an_analysis_carry_no_filter(): void
    {
        [$user] = $this->context();

        foreach (['/bausteine', '/einstellungen/konto/profil'] as $path) {
            $this->actingAs($user)
                ->get($path)
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page->where('filter', null));
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }
}
