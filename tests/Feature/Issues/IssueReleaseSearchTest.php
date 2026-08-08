<?php

namespace Tests\Feature\Issues;

use App\Models\Issue;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Release;
use App\Models\User;
use App\Support\Issues\IssueSearch;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Die Suche nach der ausgelieferten Version in der Fehlerliste.
 *
 * Sie ist der Anfang der Suchsprache und nicht die Sprache selbst — `is:`,
 * `browser:`, Klammern und Verneinung kommen mit S4. Geprüft wird deshalb
 * beides: dass die beiden Begriffe wirken **und** dass ein noch unbekannter
 * Begriff nicht stillschweigend übergangen wird.
 */
class IssueReleaseSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(CarbonImmutable::parse('2026-03-10 12:00:00', 'UTC'));
    }

    /**
     * @return array{User, Project}
     */
    private function context(): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user)->create();
        $project = Project::factory()->for($organization)->create();

        $user->switchOrganization($organization);

        return [$user, $project];
    }

    private function issue(Project $project, string $title, ?Release $first, ?Release $last): Issue
    {
        return Issue::factory()->for($project)->create([
            'title' => $title,
            'first_seen' => Carbon::now()->subHours(6),
            'last_seen' => Carbon::now()->subHour(),
            'first_release_id' => $first?->id,
            'first_release_at' => $first === null ? null : Carbon::now()->subHours(6),
            'last_release_id' => $last?->id,
            'last_release_at' => $last === null ? null : Carbon::now()->subHour(),
        ]);
    }

    public function test_first_release_finds_what_started_in_that_version(): void
    {
        [$user, $project] = $this->context();

        $one = Release::factory()->for($project)->version('1.0.0')->create();
        $two = Release::factory()->for($project)->version('1.1.0')->create();

        $this->issue($project, 'Alt', $one, $two);
        $this->issue($project, 'Neu', $two, $two);

        $this->actingAs($user)
            ->get(route('issues.index', ['q' => 'firstRelease:1.1.0']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues.data', 1)
                ->where('issues.data.0.title', 'Neu')
            );
    }

    public function test_release_finds_both_the_first_and_the_last_version(): void
    {
        [$user, $project] = $this->context();

        $one = Release::factory()->for($project)->version('1.0.0')->create();
        $two = Release::factory()->for($project)->version('1.1.0')->create();

        $this->issue($project, 'Beginnt in 1.0.0', $one, $two);
        $this->issue($project, 'Nur in 1.1.0', $two, $two);

        $this->actingAs($user)
            ->get(route('issues.index', ['q' => 'release:1.0.0']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues.data', 1)
                ->where('issues.data.0.title', 'Beginnt in 1.0.0')
            );
    }

    public function test_the_list_carries_the_versions_of_each_issue(): void
    {
        [$user, $project] = $this->context();

        $one = Release::factory()->for($project)->version('1.0.0')->create();
        $two = Release::factory()->for($project)->version('1.1.0')->create();

        $this->issue($project, 'Mit Versionen', $one, $two);

        $this->actingAs($user)
            ->get(route('issues.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('issues.data.0.firstRelease', '1.0.0')
                ->where('issues.data.0.lastRelease', '1.1.0')
            );
    }

    public function test_an_issue_without_a_version_carries_null(): void
    {
        [$user, $project] = $this->context();

        $this->issue($project, 'Ohne Version', null, null);

        $this->actingAs($user)
            ->get(route('issues.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('issues.data.0.firstRelease', null)
                ->where('issues.data.0.lastRelease', null)
            );
    }

    /**
     * Ein Begriff, den die Suche noch nicht kennt, filtert nichts — und darf
     * deshalb nicht so aussehen, als hätte er gewirkt.
     */
    public function test_an_unknown_term_is_reported_back(): void
    {
        [$user, $project] = $this->context();

        $release = Release::factory()->for($project)->version('1.0.0')->create();

        $this->issue($project, 'Irgendein Fehler', $release, $release);

        $this->actingAs($user)
            ->get(route('issues.index', ['q' => 'is:unresolved release:1.0.0']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues.data', 1)
                ->where('unsupportedTerms', ['is:unresolved'])
            );
    }

    /**
     * Die Eingabe steht unverändert im Feld: wer `firstRelease:` tippt, soll
     * nicht `firstrelease:` zurückbekommen.
     */
    public function test_the_input_is_returned_verbatim(): void
    {
        [$user] = $this->context();

        $this->actingAs($user)
            ->get(route('issues.index', ['q' => 'firstRelease:1.0.0']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('list.q', 'firstRelease:1.0.0')
            );
    }

    public function test_the_key_is_case_insensitive_but_the_value_is_not(): void
    {
        $search = IssueSearch::parse('FIRSTRELEASE:1.0.0-RC1');

        $this->assertSame(['1.0.0-RC1'], $search->firstReleases);
        $this->assertSame([], $search->unsupported);
    }

    public function test_a_quoted_value_may_contain_spaces(): void
    {
        $search = IssueSearch::parse('release:"1.0.0 beta"');

        $this->assertSame(['1.0.0 beta'], $search->releases);
    }

    public function test_several_values_of_one_key_are_an_or(): void
    {
        [$user, $project] = $this->context();

        $one = Release::factory()->for($project)->version('1.0.0')->create();
        $two = Release::factory()->for($project)->version('1.1.0')->create();
        $three = Release::factory()->for($project)->version('1.2.0')->create();

        $this->issue($project, 'Eins', $one, $one);
        $this->issue($project, 'Zwei', $two, $two);
        $this->issue($project, 'Drei', $three, $three);

        $this->actingAs($user)
            ->get(route('issues.index', ['q' => 'firstRelease:1.0.0 firstRelease:1.2.0']))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('issues.data', 2));
    }

    /**
     * Ohne Suchbegriff bleibt die Liste, was sie war — die Suche ist ein
     * Zusatz und kein neuer Pflichtfilter.
     */
    public function test_without_a_term_nothing_is_filtered(): void
    {
        [$user, $project] = $this->context();

        $this->issue($project, 'Ohne Version', null, null);

        $this->actingAs($user)
            ->get(route('issues.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues.data', 1)
                ->where('unsupportedTerms', [])
            );
    }
}
