<?php

namespace Tests\Feature\Issues;

use App\Models\Issue;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Support\Tags\TagAggregates;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Die Merkmal-Ansichten: was sie zeigen, wie der Anteil zustande kommt und was
 * ein Klick auf einen Wert bewirkt.
 *
 * Die Zähler werden hier **von Hand** gesetzt statt über die Kette erzeugt: die
 * Frage dieser Prüfung ist die Darstellung, und ein Aufbau über Dutzende
 * Ereignisse würde sie an das Mitschreiben ketten, das an anderer Stelle geprüft
 * wird ({@see TagAggregationTest}).
 */
class TagFacetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

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

    private function issue(Project $project): Issue
    {
        return Issue::factory()->for($project)->create([
            'title' => 'TypeError: undefined is not a function',
            'first_seen' => Carbon::now()->subHours(6),
            'last_seen' => Carbon::now()->subHour(),
            'times_seen' => 100,
        ]);
    }

    /**
     * Merkmal-Zähler, wie die Aufnahme sie hinterlassen hätte.
     *
     * @param  array<string, int>  $values
     */
    private function tags(Project $project, Issue $issue, string $key, array $values, ?int $total = null): void
    {
        $total ??= array_sum($values);

        $this->bump('issue_tag_keys', ['issue_id' => $issue->id, 'project_id' => $project->id, 'tag_key' => $key], $total, count($values));
        $this->bump('project_tag_keys', ['project_id' => $project->id, 'tag_key' => $key], $total, count($values));

        foreach ($values as $value => $count) {
            $this->bump('issue_tags', ['issue_id' => $issue->id, 'project_id' => $project->id, 'tag_key' => $key, 'tag_value' => (string) $value], $count);
            $this->bump('project_tags', ['project_id' => $project->id, 'tag_key' => $key, 'tag_value' => (string) $value], $count);
        }
    }

    /**
     * Eine Zähler-Zeile anlegen oder fortschreiben.
     *
     * Fortschreiben und nicht nur anlegen, weil die Projekt-Ebene über mehrere
     * Fehler hinweg dieselbe Zeile trifft — genau wie im Betrieb.
     *
     * @param  array<string, mixed>  $key
     */
    private function bump(string $table, array $key, int $times, ?int $valueCount = null): void
    {
        $now = Carbon::now();
        $existing = DB::table($table)->where($key)->first();

        if ($existing !== null) {
            DB::table($table)->where($key)->update(array_filter([
                'times_seen' => $existing->times_seen + $times,
                'value_count' => $valueCount === null ? null : $existing->value_count + $valueCount,
                'updated_at' => $now,
            ], static fn (mixed $value): bool => $value !== null));

            return;
        }

        DB::table($table)->insert($key + array_filter([
            'times_seen' => $times,
            'value_count' => $valueCount,
            'first_seen' => $valueCount === null ? $now : null,
            'last_seen' => $valueCount === null ? $now : null,
            'created_at' => $now,
            'updated_at' => $now,
        ], static fn (mixed $value): bool => $value !== null));
    }

    public function test_the_issue_shows_its_tags_with_a_share(): void
    {
        [$user, , $project] = $this->context();
        $issue = $this->issue($project);

        $this->tags($project, $issue, 'browser', ['Chrome 124' => 75, 'Firefox 125' => 25]);

        $this->actingAs($user)
            ->get(route('issues.tags.index', $issue))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('issues/Tags')
                ->where('issue.id', $issue->id)
                ->has('facets', 1)
                ->where('facets.0.key', 'browser')
                ->where('facets.0.label', 'Browser')
                ->where('facets.0.total', 100)
                ->has('facets.0.values', 2)
                ->where('facets.0.values.0.value', 'Chrome 124')
                ->where('facets.0.values.0.count', 75)
                ->where('facets.0.values.0.share', 75)
                ->where('facets.0.values.1.value', 'Firefox 125')
                ->where('facets.0.values.1.share', 25)
                ->etc()
            );
    }

    public function test_the_share_is_measured_against_the_tag_and_not_against_the_shown_values(): void
    {
        [$user, , $project] = $this->context();
        $issue = $this->issue($project);

        // Zwei aufgehobene Werte, aber vierhundert Ereignisse mit diesem
        // Merkmal: der Rest fiel unter die Obergrenze. Ohne eigenen Nenner käme
        // hier „75 % / 25 %" heraus — eine Zahl, die falsch ist und richtig
        // aussieht.
        $this->tags($project, $issue, 'auftrag', ['nr-1' => 75, 'nr-2' => 25], total: 400);

        $this->actingAs($user)
            ->get(route('issues.tags.index', $issue))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('facets.0.total', 400)
                ->where('facets.0.values.0.share', 18.8)
                ->where('facets.0.rest.count', 300)
                ->where('facets.0.rest.share', 75)
                ->etc()
            );
    }

    public function test_the_detail_page_lists_every_value_of_one_tag(): void
    {
        [$user, , $project] = $this->context();
        $issue = $this->issue($project);

        $this->tags($project, $issue, 'browser', ['Chrome 124' => 60, 'Firefox 125' => 30, 'Safari 17' => 10]);

        $this->actingAs($user)
            ->get(route('issues.tags.show', [$issue, 'browser']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('issues/Tags')
                ->where('detail.key', 'browser')
                ->has('detail.values', 3)
                ->where('detail.valueCount', 3)
                ->where('detail.capped', false)
                ->where('valueLimit', TagAggregates::MAX_VALUES_PER_KEY)
                ->etc()
            );
    }

    public function test_an_unknown_tag_is_not_an_empty_page_but_a_missing_one(): void
    {
        [$user, , $project] = $this->context();
        $issue = $this->issue($project);

        $this->actingAs($user)
            ->get(route('issues.tags.show', [$issue, 'browser']))
            ->assertNotFound();
    }

    public function test_a_stranger_does_not_get_to_see_the_tags(): void
    {
        [, $organization, $project] = $this->context();
        $issue = $this->issue($project);

        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->get(route('issues.tags.index', ['organization' => $organization, 'issue' => $issue]))
            ->assertForbidden();
    }

    public function test_the_project_wide_overview_sums_across_the_selection(): void
    {
        [$user, $organization, $project] = $this->context();
        $other = Project::factory()->for($organization)->create(['name' => 'Kasse', 'slug' => 'kasse']);

        $this->tags($project, $this->issue($project), 'server_name', ['web-07' => 40]);
        $this->tags($other, $this->issue($other), 'server_name', ['web-07' => 60]);

        $this->actingAs($user)
            ->get(route('tags.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('tags/Index')
                ->has('facets', 1)
                ->where('facets.0.key', 'server_name')
                ->where('facets.0.total', 100)
                ->where('facets.0.values.0.value', 'web-07')
                ->where('facets.0.values.0.count', 100)
                ->etc()
            );
    }

    public function test_a_click_on_a_value_narrows_the_issue_list_down_to_it(): void
    {
        [$user, , $project] = $this->context();

        $chrome = $this->issue($project);
        $firefox = Issue::factory()->for($project)->create([
            'title' => 'RangeError: invalid array length',
            'first_seen' => Carbon::now()->subHours(6),
            'last_seen' => Carbon::now()->subHour(),
        ]);

        $this->tags($project, $chrome, 'browser', ['Chrome 124' => 100]);
        $this->tags($project, $firefox, 'browser', ['Firefox 125' => 100]);

        // Der Klick schreibt einen Suchausdruck und keinen eigenen Parameter:
        // die Einschränkung steht damit dort, wo man sie ändern und ergänzen
        // kann — im Suchfeld. Genau diese Adresse baut TagLinks.
        $this->actingAs($user)
            ->get(route('issues.index', ['q' => 'browser:"Chrome 124"']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('issues/Index')
                ->has('issues.data', 1)
                ->where('issues.data.0.id', $chrome->id)
                ->where('list.q', 'browser:"Chrome 124"')
                ->etc()
            );
    }

    /**
     * Und der Klick erzeugt tatsächlich diese Adresse — sonst prüfte die
     * Erwartung oben nur sich selbst.
     */
    public function test_the_link_of_a_value_carries_that_expression(): void
    {
        [$user, , $project] = $this->context();

        $this->tags($project, $this->issue($project), 'browser', ['Chrome 124' => 100]);

        $this->actingAs($user)
            ->get(route('tags.show', 'browser'))
            ->assertInertia(function (AssertableInertia $page): void {
                $href = (string) $page->toArray()['props']['detail']['values'][0]['href'];

                $this->assertStringContainsString(rawurlencode('browser:"Chrome 124"'), $href);
            });
    }
}
