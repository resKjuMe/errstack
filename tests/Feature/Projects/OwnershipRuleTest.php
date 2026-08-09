<?php

namespace Tests\Feature\Projects;

use App\Enums\OrganizationRole;
use App\Enums\OwnershipMatcher;
use App\Models\Organization;
use App\Models\OwnershipRule;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Die Pflege der Zuständigkeits-Regeln: anlegen, ändern, importieren,
 * ausprobieren.
 *
 * Zwei Dinge stehen hier ausdrücklich in Prüfungen, weil sie die Aussagen des
 * Regelwerks tragen und beim Umbauen still kaputtgehen könnten: dass die
 * **zuletzt** passende Regel gewinnt, und dass ein Import keine Regeln anlegt,
 * die niemanden benennen. Das zweite ist der Unterschied zwischen einer Liste,
 * die vollständig ist, und einer, die vollständig **aussieht**.
 */
class OwnershipRuleTest extends TestCase
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

    private function path(Organization $organization, Project $project, string $suffix = ''): string
    {
        return "/einstellungen/organisationen/{$organization->slug}/projekte/{$project->slug}/zustaendigkeit{$suffix}";
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'matcher' => 'path',
            'pattern' => 'src/billing/*',
            'owners' => ['#Kasse'],
        ], $overrides);
    }

    public function test_the_page_lists_the_rules_of_the_project(): void
    {
        [$user, $organization, $project] = $this->project();

        OwnershipRule::factory()->for($project)->create();

        $this->actingAs($user)
            ->get($this->path($organization, $project))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('projects/Ownership')
                ->has('rules', 1)
                ->where('project.autoAssign', false)
                ->where('canManage', true)
                ->etc(),
            );
    }

    /**
     * Ansehen darf jedes Mitglied: die Liste ist die Antwort auf „warum steht
     * mein Name an diesem Fehler?" — und diese Frage stellt gerade der, der die
     * Regeln nicht ändern darf.
     */
    public function test_a_member_may_look_but_not_change(): void
    {
        [$user, $organization, $project] = $this->project(OrganizationRole::Member);

        $this->actingAs($user)
            ->get($this->path($organization, $project))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canManage', false)->etc());

        $this->actingAs($user)
            ->post($this->path($organization, $project), $this->payload())
            ->assertForbidden();

        $this->assertSame(0, $project->ownershipRules()->count());
    }

    public function test_a_new_rule_is_appended_and_therefore_overrides_the_ones_above(): void
    {
        [$user, $organization, $project] = $this->project();

        OwnershipRule::factory()->for($project)->at(0)->create(['pattern' => 'src/*']);

        $this->actingAs($user)
            ->post($this->path($organization, $project), $this->payload())
            ->assertRedirect();

        $rule = $project->ownershipRules()->orderByDesc('position')->first();

        $this->assertSame('src/billing/*', $rule->pattern);
        $this->assertSame(1, $rule->position);
    }

    public function test_a_tag_rule_without_its_key_is_rejected(): void
    {
        [$user, $organization, $project] = $this->project();

        $this->actingAs($user)
            ->post($this->path($organization, $project), $this->payload([
                'matcher' => 'tag',
                'pattern' => 'web-*',
            ]))
            ->assertSessionHasErrors('tag_key');
    }

    /**
     * `me` und `none` sind Wörter der Suchsprache und bezeichnen niemand
     * Bestimmtes. Eine Regel damit sähe aus, als weise sie zu.
     */
    public function test_a_rule_cannot_be_owned_by_nobody_in_particular(): void
    {
        [$user, $organization, $project] = $this->project();

        foreach (['me', 'none'] as $owner) {
            $this->actingAs($user)
                ->post($this->path($organization, $project), $this->payload(['owners' => [$owner]]))
                ->assertSessionHasErrors('owners.0');
        }

        $this->assertSame(0, $project->ownershipRules()->count());
    }

    public function test_a_rule_can_be_switched_off_and_deleted(): void
    {
        [$user, $organization, $project] = $this->project();

        $rule = OwnershipRule::factory()->for($project)->create();

        $this->actingAs($user)
            ->post($this->path($organization, $project, "/{$rule->id}/zustand"))
            ->assertRedirect();

        $this->assertFalse($rule->fresh()?->is_active);

        $this->actingAs($user)
            ->delete($this->path($organization, $project, "/{$rule->id}"))
            ->assertRedirect();

        $this->assertSame(0, $project->ownershipRules()->count());
    }

    public function test_automatic_assignment_is_a_switch(): void
    {
        [$user, $organization, $project] = $this->project();

        $this->actingAs($user)
            ->patch($this->path($organization, $project, '/automatik'), ['enabled' => true])
            ->assertRedirect();

        $this->assertTrue($project->fresh()?->ownership_auto_assign);
    }

    /**
     * Die Vorschau beantwortet die Frage vor dem Einschalten — und zeigt dabei
     * auch, was überstimmt wurde.
     */
    public function test_the_preview_names_the_winning_rule(): void
    {
        [$user, $organization, $project] = $this->project();
        $team = Team::factory()->for($organization)->create(['name' => 'Kasse']);

        OwnershipRule::factory()->for($project)->at(0)
            ->matching(OwnershipMatcher::Path, 'src/*', ['#Plattform'])->create();
        OwnershipRule::factory()->for($project)->at(1)
            ->matching(OwnershipMatcher::Path, 'src/billing/*', ['#'.$team->name])->create();

        $this->actingAs($user)
            ->postJson($this->path($organization, $project, '/vorschau'), [
                'path' => 'src/billing/Invoice.php',
            ])
            ->assertOk()
            ->assertJsonPath('assignee.label', '#Kasse')
            ->assertJsonPath('matches.1.winner', true)
            ->assertJsonPath('matches.0.winner', false);
    }

    public function test_the_preview_says_when_nothing_was_given(): void
    {
        [$user, $organization, $project] = $this->project();

        $this->actingAs($user)
            ->postJson($this->path($organization, $project, '/vorschau'), [])
            ->assertOk()
            ->assertJsonPath('empty', true)
            ->assertJsonPath('assignee', null);
    }

    /**
     * Der Import: Kommentare und Leerzeilen werden übergangen, Verzeichnisse
     * bekommen ihren Platzhalter, und die Reihenfolge der Datei bleibt die
     * Reihenfolge der Liste.
     */
    public function test_codeowners_is_imported_in_file_order(): void
    {
        [$user, $organization, $project] = $this->project();
        Team::factory()->for($organization)->create(['name' => 'Kasse']);
        Team::factory()->for($organization)->create(['name' => 'Plattform']);

        $this->actingAs($user)
            ->post($this->path($organization, $project, '/import'), [
                'contents' => "# Zuständigkeiten\n\n*              @acme/plattform\n/src/billing/  @acme/kasse\n",
            ])
            ->assertRedirect();

        $rules = $project->ownershipRules()->inOrder()->get();

        $this->assertCount(2, $rules);
        $this->assertSame('*', $rules[0]->pattern);
        $this->assertSame(['#plattform'], $rules[0]->owners);
        $this->assertSame('src/billing/*', $rules[1]->pattern);
        $this->assertSame(OwnershipRule::SOURCE_CODEOWNERS, $rules[1]->source);
        // Die zweite Zeile steht hinter der ersten und überstimmt sie damit —
        // genau wie in der Datei.
        $this->assertLessThan($rules[1]->position, $rules[0]->position);
    }

    /**
     * Eine Zeile, deren Zuständige es hier nicht gibt, ergäbe eine Regel, die
     * niemandem etwas zuweist. Sie wird übersprungen und nicht angelegt.
     */
    public function test_codeowners_skips_lines_whose_owners_are_unknown(): void
    {
        [$user, $organization, $project] = $this->project();
        Team::factory()->for($organization)->create(['name' => 'Kasse']);

        $this->actingAs($user)
            ->post($this->path($organization, $project, '/import'), [
                'contents' => "/src/billing/ @acme/kasse\n/src/suche/ @acme/gibt-es-nicht\n",
            ])
            ->assertRedirect();

        $this->assertSame(1, $project->ownershipRules()->count());
        $this->assertSame('src/billing/*', $project->ownershipRules()->sole()->pattern);
    }

    /**
     * Der Import hängt an und ersetzt nicht: die von Hand geschriebenen
     * Ausnahmen zu verlieren wäre die naheliegende Lesart von „übernehmen" und
     * die gefährliche.
     */
    public function test_codeowners_is_appended_and_does_not_replace(): void
    {
        [$user, $organization, $project] = $this->project();
        Team::factory()->for($organization)->create(['name' => 'Kasse']);

        OwnershipRule::factory()->for($project)->create(['pattern' => 'von-hand/*']);

        $this->actingAs($user)
            ->post($this->path($organization, $project, '/import'), [
                'contents' => "/src/billing/ @acme/kasse\n",
            ])
            ->assertRedirect();

        $this->assertSame(2, $project->ownershipRules()->count());
    }

    public function test_a_rule_of_another_project_cannot_be_touched(): void
    {
        [$user, $organization, $project] = $this->project();
        $other = Project::factory()->for($organization)->create(['name' => 'Andere', 'slug' => 'andere']);

        $rule = OwnershipRule::factory()->for($other)->create();

        $this->actingAs($user)
            ->delete($this->path($organization, $project, "/{$rule->id}"))
            ->assertNotFound();

        $this->assertSame(1, $other->ownershipRules()->count());
    }
}
