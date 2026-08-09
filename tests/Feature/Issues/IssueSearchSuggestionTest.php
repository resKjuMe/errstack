<?php

namespace Tests\Feature\Issues;

use App\Models\Organization;
use App\Models\Project;
use App\Models\Release;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Die Vorschläge des Suchfeldes.
 *
 * Sie sind der Unterschied zwischen einer Sprache, die man auswendig können
 * muss, und einer Liste, durch die man tippt. Geprüft wird deshalb nicht nur,
 * **dass** etwas vorgeschlagen wird, sondern dass die Stelle des Schreibmarkers
 * gilt: wer mitten in einem Ausdruck steht, soll Werte zu **seinem** Feld
 * bekommen und nicht zum ersten.
 */
class IssueSearchSuggestionTest extends TestCase
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

    /**
     * Ein Merkmal auf Projektebene, wie die Aufnahme es hinterlassen hätte.
     */
    private function tag(Project $project, string $key, string $value, int $times = 1): void
    {
        $now = Carbon::now();

        DB::table('project_tag_keys')->updateOrInsert(
            ['project_id' => $project->id, 'tag_key' => $key],
            ['times_seen' => $times, 'value_count' => 1, 'created_at' => $now, 'updated_at' => $now],
        );

        DB::table('project_tags')->updateOrInsert(
            ['project_id' => $project->id, 'tag_key' => $key, 'tag_value' => $value],
            ['times_seen' => $times, 'first_seen' => $now, 'last_seen' => $now, 'created_at' => $now, 'updated_at' => $now],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function suggest(User $user, string $input, ?int $cursor = null): array
    {
        $response = $this->actingAs($user)->getJson(route('issues.search.suggest', array_filter([
            'q' => $input,
            'cursor' => $cursor,
        ], static fn (mixed $value): bool => $value !== null)));

        $response->assertOk();

        /** @var array<string, mixed> $data */
        $data = $response->json();

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function values(array $data): array
    {
        /** @var list<array{value: string}> $suggestions */
        $suggestions = $data['suggestions'];

        return array_map(fn (array $one): string => $one['value'], $suggestions);
    }

    public function test_an_empty_field_offers_the_fields(): void
    {
        [$user] = $this->context();

        $data = $this->suggest($user, '');

        $this->assertSame('field', $data['context']);
        $this->assertContains('is:', $this->values($data));
        $this->assertContains('level:', $this->values($data));
    }

    public function test_a_prefix_narrows_the_fields(): void
    {
        [$user] = $this->context();

        $this->assertSame(['firstSeen:', 'firstRelease:'], $this->values($this->suggest($user, 'first')));
    }

    /**
     * Der Halbsatz hinter dem Feld beantwortet „und was steht da rein?".
     */
    public function test_a_field_comes_with_a_hint(): void
    {
        [$user] = $this->context();

        /** @var list<array{value: string, hint: string|null}> $suggestions */
        $suggestions = $this->suggest($user, 'is')['suggestions'];

        $this->assertSame('is:', $suggestions[0]['value']);
        $this->assertStringContainsString('unresolved', (string) $suggestions[0]['hint']);
    }

    public function test_the_tags_of_the_selected_projects_are_fields_too(): void
    {
        [$user, $project] = $this->context();

        $this->tag($project, 'browser', 'Chrome 124');

        $this->assertContains('browser:', $this->values($this->suggest($user, 'brow')));
    }

    public function test_a_field_with_a_colon_offers_its_values(): void
    {
        [$user] = $this->context();

        $data = $this->suggest($user, 'is:');

        $this->assertSame('value', $data['context']);
        $this->assertSame('is', $data['field']);
        $this->assertContains('is:unresolved', $this->values($data));
    }

    public function test_tag_values_come_from_the_selected_projects(): void
    {
        [$user, $project] = $this->context();

        $this->tag($project, 'browser', 'Chrome 124');
        $this->tag($project, 'browser', 'Firefox 125');

        $this->assertSame(
            ['browser:"Chrome 124"'],
            $this->values($this->suggest($user, 'browser:Chrome')),
        );
    }

    public function test_releases_come_from_the_selected_projects(): void
    {
        [$user, $project] = $this->context();

        Release::factory()->for($project)->version('1.1.0')->create(['last_event_at' => Carbon::now()]);

        $this->assertSame(['release:1.1.0'], $this->values($this->suggest($user, 'release:1.')));
    }

    /**
     * Der Schreibmarker entscheidet, nicht das Ende der Eingabe: wer hinter
     * `lev` steht, meint das Feld darunter und nicht den Rest dahinter.
     */
    public function test_the_cursor_decides_which_term_is_meant(): void
    {
        [$user] = $this->context();

        $data = $this->suggest($user, 'lev browser:Chrome', 3);

        $this->assertSame('field', $data['context']);
        $this->assertSame(0, $data['from']);
        $this->assertSame(3, $data['to']);
        $this->assertContains('level:', $this->values($data));
    }

    /**
     * Ein halb getipptes Anführungszeichen ist für den Zerleger ein Fehler und
     * für das Suchfeld der Normalfall — es darf die Vorschläge nicht abwürgen.
     */
    public function test_a_half_typed_quote_still_gets_suggestions(): void
    {
        [$user, $project] = $this->context();

        $this->tag($project, 'browser', 'Chrome 124');

        $data = $this->suggest($user, 'browser:"Chrome 1');

        $this->assertSame('value', $data['context']);
        $this->assertSame(0, $data['from']);
        $this->assertSame(['browser:"Chrome 124"'], $this->values($data));
    }

    public function test_the_suggestions_need_a_signed_in_viewer(): void
    {
        [, $project] = $this->context();

        $this->get(route('issues.search.suggest', $project->organization))
            ->assertRedirect(route('login'));
    }
}
