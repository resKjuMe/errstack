<?php

namespace Tests\Feature\Projects;

use App\Enums\DiscardOrigin;
use App\Enums\DiscardReason;
use App\Enums\InboundFilterKind;
use App\Enums\OrganizationRole;
use App\Models\InboundFilterRule;
use App\Models\IngestDiscard;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Ingest\InboundFilterTest;
use Tests\TestCase;

/**
 * Die Filterseite eines Projekts: Schalter, Listen, Zählung.
 *
 * Geprüft wird die Bedienung, nicht die Wirkung — was ein Filter trifft, steht
 * in {@see InboundFilterTest}. Drei Zusagen gehören hierher: die Zählung je
 * Filterart ist einsehbar, ein unbrauchbarer Eintrag kommt gar nicht erst in
 * die Liste, und wer ein Projekt nicht verwalten darf, kann die Filter ansehen,
 * aber nicht ändern.
 */
class InboundFilterSettingsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{User, Organization, Project}
     */
    private function project(OrganizationRole $role = OrganizationRole::Owner): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user, $role)->create();
        $project = Project::factory()->for($organization)->create(['name' => 'Webshop', 'slug' => 'webshop']);

        return [$user, $organization, $project];
    }

    private function path(Organization $organization, Project $project): string
    {
        return "/einstellungen/organisationen/{$organization->slug}/projekte/{$project->slug}/filter";
    }

    public function test_the_page_lists_every_filter_kind_with_its_switch(): void
    {
        [$owner, $organization, $project] = $this->project();

        $this->actingAs($owner)->get($this->path($organization, $project))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('projects/Filters')
                ->where('permissions.manage', true)
                ->has('kinds', count(InboundFilterKind::cases()))
                ->where('kinds.0.enabled', false)
                // Ohne eigenen Eintrag gelten die Vorgaben — sie stehen auf der
                // Seite, statt nur erwähnt zu werden.
                ->has('browserDefaults')
                ->has('knownHosts')
            );
    }

    /**
     * Die Zusage der Aufgabe: die Anzahl gefilterter Ereignisse ist **je
     * Filterart** einsehbar. Eine Gesamtzahl allein sagt nicht, welcher Schalter
     * zu viel nimmt.
     */
    public function test_the_page_shows_how_much_each_filter_took(): void
    {
        [$owner, $organization, $project] = $this->project();

        self::record($project, InboundFilterKind::Crawler, 7);
        self::record($project, InboundFilterKind::Localhost, 2);

        $this->actingAs($owner)->get($this->path($organization, $project))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filtered', 9)
                ->where(
                    'kinds',
                    fn (Collection $kinds): bool => $kinds
                        ->firstWhere('value', InboundFilterKind::Crawler->value)['filtered'] === 7
                        && $kinds
                            ->firstWhere('value', InboundFilterKind::Localhost->value)['filtered'] === 2,
                )
            );
    }

    /**
     * Gezählt wird der jüngste Zeitraum. Ein Zähler von vor einem halben Jahr
     * beantwortet die Frage „nimmt der Filter gerade zu viel weg?" nicht.
     */
    public function test_the_count_ignores_what_lies_outside_the_window(): void
    {
        [$owner, $organization, $project] = $this->project();

        self::record($project, InboundFilterKind::Crawler, 5, Carbon::now()->subDays(90));

        $this->actingAs($owner)->get($this->path($organization, $project))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('filtered', 0));
    }

    public function test_the_switches_survive_a_reload(): void
    {
        [$owner, $organization, $project] = $this->project();

        $this->actingAs($owner)->patch($this->path($organization, $project), self::switches([
            'filter_crawlers' => true,
            'filter_localhost' => true,
        ]))->assertSessionHasNoErrors();

        $project->refresh();

        $this->assertTrue($project->filter_crawlers);
        $this->assertTrue($project->filter_localhost);
        $this->assertFalse($project->filter_browser_extensions);
    }

    public function test_an_entry_can_be_added_paused_and_deleted(): void
    {
        [$owner, $organization, $project] = $this->project();
        $path = $this->path($organization, $project);

        $this->actingAs($owner)->post($path.'/eintraege', [
            'kind' => InboundFilterKind::MessagePattern->value,
            'expression' => '*ResizeObserver loop*',
        ])->assertSessionHasNoErrors();

        $rule = InboundFilterRule::query()->sole();

        $this->assertSame($project->id, $rule->project_id);
        $this->assertTrue($rule->is_active);

        $this->actingAs($owner)->post($path."/eintraege/{$rule->id}/zustand")->assertSessionHasNoErrors();
        $this->assertFalse($rule->refresh()->is_active);

        $this->actingAs($owner)->delete($path."/eintraege/{$rule->id}")->assertSessionHasNoErrors();
        $this->assertSame(0, InboundFilterRule::query()->count());
    }

    /**
     * Ein Eintrag aus lauter Platzhaltern trifft jede Meldung — die einzige
     * Eingabe, mit der sich ein Projekt in einem Zug still stellen lässt.
     */
    public function test_an_entry_that_matches_everything_is_refused(): void
    {
        [$owner, $organization, $project] = $this->project();

        $this->actingAs($owner)->post($this->path($organization, $project).'/eintraege', [
            'kind' => InboundFilterKind::MessagePattern->value,
            'expression' => '**',
        ])->assertSessionHasErrors('expression');

        $this->assertSame(0, InboundFilterRule::query()->count());
    }

    public function test_an_unusable_address_and_an_unusable_threshold_are_refused(): void
    {
        [$owner, $organization, $project] = $this->project();
        $path = $this->path($organization, $project).'/eintraege';

        $this->actingAs($owner)->post($path, [
            'kind' => InboundFilterKind::IpAddress->value,
            'expression' => '203.0.113.*',
        ])->assertSessionHasErrors('expression');

        $this->actingAs($owner)->post($path, [
            'kind' => InboundFilterKind::LegacyBrowser->value,
            'expression' => 'safari:sechs',
        ])->assertSessionHasErrors('expression');

        // Beides in gültiger Form geht dagegen durch.
        $this->actingAs($owner)->post($path, [
            'kind' => InboundFilterKind::IpAddress->value,
            'expression' => '203.0.113.0/24',
        ])->assertSessionHasNoErrors();

        $this->actingAs($owner)->post($path, [
            'kind' => InboundFilterKind::LegacyBrowser->value,
            'expression' => 'safari:6',
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, InboundFilterRule::query()->count());
    }

    /**
     * Eine Art ohne Liste lässt sich nicht mit Einträgen versehen — sie hätte
     * keine, in der sie stünden, und der Eintrag wäre eine Zeile, die nie
     * greift.
     */
    public function test_an_entry_for_a_switch_only_kind_is_refused(): void
    {
        [$owner, $organization, $project] = $this->project();

        $this->actingAs($owner)->post($this->path($organization, $project).'/eintraege', [
            'kind' => InboundFilterKind::Crawler->value,
            'expression' => '*bot*',
        ])->assertSessionHasErrors('kind');
    }

    /**
     * Die Art eines bestehenden Eintrags lässt sich nicht wechseln — derselbe
     * Text gälte dann plötzlich gegen ein anderes Feld, und die Prüfung, die er
     * beim Anlegen bestanden hat, wäre die falsche gewesen.
     */
    public function test_the_kind_of_an_existing_entry_cannot_be_switched(): void
    {
        [$owner, $organization, $project] = $this->project();

        $rule = InboundFilterRule::factory()
            ->forProject($project)
            ->of(InboundFilterKind::MessagePattern, '*Kaputt*')
            ->create();

        $this->actingAs($owner)->patch($this->path($organization, $project)."/eintraege/{$rule->id}", [
            'kind' => InboundFilterKind::Release->value,
            'expression' => '*Kaputt*',
        ])->assertSessionHasErrors('kind');

        $this->assertSame(InboundFilterKind::MessagePattern, $rule->refresh()->kind);
    }

    /**
     * Jeder Eintrag ist ein Vergleich für **jede** eingehende Meldung. Eine aus
     * einem Protokoll hineinkopierte Liste wäre bei einer Fehlerflut genau die
     * Bremse, die der Filter vermeiden soll.
     */
    public function test_the_list_of_a_kind_is_capped(): void
    {
        [$owner, $organization, $project] = $this->project();

        InboundFilterRule::factory()
            ->count(InboundFilterRule::MAX_PER_KIND)
            ->forProject($project)
            ->of(InboundFilterKind::Release, 'kanarienvogel-*')
            ->create();

        $this->actingAs($owner)->post($this->path($organization, $project).'/eintraege', [
            'kind' => InboundFilterKind::Release->value,
            'expression' => 'einer-zu-viel',
        ])->assertSessionHasErrors('expression');

        // Eine andere Art bleibt davon unberührt — die Grenze gilt je Liste.
        $this->actingAs($owner)->post($this->path($organization, $project).'/eintraege', [
            'kind' => InboundFilterKind::MessagePattern->value,
            'expression' => '*Kaputt*',
        ])->assertSessionHasNoErrors();
    }

    public function test_a_member_may_look_but_not_change(): void
    {
        [$member, $organization, $project] = $this->project(OrganizationRole::Member);

        $this->actingAs($member)->get($this->path($organization, $project))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('permissions.manage', false));

        $this->actingAs($member)
            ->patch($this->path($organization, $project), self::switches(['filter_crawlers' => true]))
            ->assertForbidden();

        $this->assertFalse($project->refresh()->filter_crawlers);
    }

    /**
     * Ein Eintrag eines fremden Projekts ist über dieses hier nicht erreichbar —
     * dafür sorgt `scopeBindings` an der Route, und das gehört geprüft: ohne die
     * Bindung ließe sich jeder Filter über jedes Projekt ändern.
     */
    public function test_an_entry_of_another_project_is_out_of_reach(): void
    {
        [$owner, $organization, $project] = $this->project();

        $other = Project::factory()->for($organization)->create(['slug' => 'anderes']);
        $rule = InboundFilterRule::factory()->forProject($other)->create();

        $this->actingAs($owner)
            ->delete($this->path($organization, $project)."/eintraege/{$rule->id}")
            ->assertNotFound();

        $this->assertSame(1, InboundFilterRule::query()->count());
    }

    // ------------------------------------------------------------------ Helfer

    /**
     * Alle sieben Schalter, mit den genannten auf „an". Das Formular schickt sie
     * zusammen, und die Prüfung verlangt sie zusammen.
     *
     * @param  array<string, bool>  $enabled
     * @return array<string, bool>
     */
    private static function switches(array $enabled = []): array
    {
        $payload = [];

        foreach (InboundFilterKind::columns() as $column) {
            $payload[$column] = $enabled[$column] ?? false;
        }

        return $payload;
    }

    private static function record(
        Project $project,
        InboundFilterKind $kind,
        int $quantity,
        ?Carbon $bucket = null,
    ): void {
        IngestDiscard::factory()->create([
            'project_id' => $project->id,
            'project_key_id' => $project->keys()->value('id'),
            'origin' => DiscardOrigin::Server,
            'reason' => DiscardReason::Filtered->value,
            'category' => $kind->value,
            'bucket' => ($bucket ?? Carbon::now())->startOfHour(),
            'quantity' => $quantity,
        ]);
    }
}
