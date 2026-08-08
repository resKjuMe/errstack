<?php

namespace Tests\Feature\Issues;

use App\Models\Issue;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Release;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Die Suche nach der ausgelieferten Version in der Fehlerliste.
 *
 * Die Sprache selbst — Klammern, Verneinung, Vergleiche — hat ihre eigene
 * Prüfung ({@see IssueSearchLanguageTest}). Hier geht es um die beiden Begriffe,
 * die eine Version meinen, und um die Grenze ihrer Auskunft: erfasst sind die
 * **erste** und die **letzte** bekannte Version, keine dazwischen.
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
     * Zustand und Version zusammen — der Begriff, der zu S1 noch nicht
     * ausgewertet werden konnte, wirkt jetzt.
     */
    public function test_a_state_and_a_release_narrow_together(): void
    {
        [$user, $project] = $this->context();

        $release = Release::factory()->for($project)->version('1.0.0')->create();

        $this->issue($project, 'Irgendein Fehler', $release, $release);

        $this->actingAs($user)
            ->get(route('issues.index', ['q' => 'is:unresolved release:1.0.0']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues.data', 1)
                ->where('unavailableTerms', [])
                ->where('searchError', null)
                ->etc()
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

    /**
     * Der Schlüssel ist gleichgültig gegenüber Groß- und Kleinschreibung, der
     * **Wert** nicht: Versionsangaben sind Bezeichner, und `1.0.0-RC1` ist nicht
     * `1.0.0-rc1`.
     */
    public function test_the_key_is_case_insensitive_but_the_value_is_not(): void
    {
        [$user, $project] = $this->context();

        $release = Release::factory()->for($project)->version('1.0.0-RC1')->create();

        $this->issue($project, 'Vorabfassung', $release, $release);

        $this->actingAs($user)
            ->get(route('issues.index', ['q' => 'FIRSTRELEASE:1.0.0-RC1']))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('issues.data', 1));

        $this->actingAs($user)
            ->get(route('issues.index', ['q' => 'firstRelease:1.0.0-rc1']))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('issues.data', 0));
    }

    public function test_a_quoted_value_may_contain_spaces(): void
    {
        [$user, $project] = $this->context();

        $release = Release::factory()->for($project)->version('1.0.0 beta')->create();

        $this->issue($project, 'Mit Leerzeichen', $release, $release);

        $this->actingAs($user)
            ->get(route('issues.index', ['q' => 'release:"1.0.0 beta"']))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('issues.data', 1));
    }

    /**
     * Zwei Angaben desselben Feldes sind ein **Und** — dieselbe Regel wie für
     * alles andere in dieser Sprache, und deshalb ohne Treffer. Wer beide meint,
     * schreibt `or`; genau dafür gibt es das Wort.
     */
    public function test_two_values_of_one_key_need_an_or(): void
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
            ->assertInertia(fn (AssertableInertia $page) => $page->has('issues.data', 0));

        $this->actingAs($user)
            ->get(route('issues.index', ['q' => 'firstRelease:1.0.0 or firstRelease:1.2.0']))
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
                ->where('unavailableTerms', [])
                ->etc()
            );
    }
}
