<?php

namespace Tests\Feature\Projects;

use App\Enums\OrganizationRole;
use App\Models\FingerprintRule;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Die Pflege der projektweiten Fingerprint-Regeln.
 *
 * Zwei Dinge stehen hier auf dem Spiel und nicht nur eines. Das eine ist das
 * übliche: wer darf was. Das andere ist die Prüfung der Eingaben, und die wiegt
 * hier schwerer als bei einem gewöhnlichen Formular — eine Regel greift bei
 * **jeder** künftigen Meldung des Projekts, und ein Fehler darin fällt nicht
 * beim Speichern auf, sondern an einer Fehlerliste, die nicht mehr stimmt.
 */
class FingerprintRuleTest extends TestCase
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
        return "/einstellungen/organisationen/{$organization->slug}/projekte/{$project->slug}/gruppierung";
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Zeitüberschreitungen zusammenfassen',
            'matchers' => [['attribute' => 'error.type', 'pattern' => '*TimeoutException']],
            'fingerprint' => ['zeitueberschreitung'],
        ], $overrides);
    }

    public function test_the_page_lists_the_rules_of_the_project(): void
    {
        [$user, $organization, $project] = $this->project();

        FingerprintRule::factory()->for($project)->create(['name' => 'Abrechnung']);

        $this->actingAs($user)
            ->get($this->path($organization, $project))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('projects/Grouping')
                ->has('rules', 1)
                ->where('rules.0.name', 'Abrechnung')
                ->where('canManage', true)
            );
    }

    public function test_a_member_may_look_but_not_change(): void
    {
        [$user, $organization, $project] = $this->project(OrganizationRole::Member);

        $this->actingAs($user)
            ->get($this->path($organization, $project))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canManage', false));

        $this->actingAs($user)
            ->post($this->path($organization, $project), $this->payload())
            ->assertForbidden();

        $this->assertSame(0, FingerprintRule::query()->count());
    }

    public function test_a_rule_can_be_created(): void
    {
        [$user, $organization, $project] = $this->project();

        $this->actingAs($user)
            ->post($this->path($organization, $project), $this->payload())
            ->assertRedirect();

        $rule = FingerprintRule::query()->sole();

        $this->assertSame($project->id, $rule->project_id);
        $this->assertSame(['zeitueberschreitung'], $rule->values());
        $this->assertTrue($rule->is_active);
    }

    /**
     * Neue Regeln kommen ans Ende. Vorne einzureihen wäre die gefährlichere
     * Wahl: die erste zutreffende Regel gewinnt, und eine frisch angelegte
     * würde alle bestehenden stillschweigend überstimmen.
     */
    public function test_a_new_rule_goes_last(): void
    {
        [$user, $organization, $project] = $this->project();

        FingerprintRule::factory()->for($project)->at(3)->create();

        $this->actingAs($user)
            ->post($this->path($organization, $project), $this->payload(['name' => 'Neu']))
            ->assertRedirect();

        $this->assertSame(4, FingerprintRule::query()->where('name', 'Neu')->sole()->position);
    }

    public function test_a_rule_needs_at_least_one_condition(): void
    {
        [$user, $organization, $project] = $this->project();

        $this->actingAs($user)
            ->post($this->path($organization, $project), $this->payload(['matchers' => []]))
            ->assertSessionHasErrors('matchers');

        $this->assertSame(0, FingerprintRule::query()->count());
    }

    /**
     * Ein Tippfehler im Feldnamen ergäbe eine Bedingung, die niemals zutrifft —
     * und eine Regel, die nie greift, sieht genauso aus wie eine, die richtig
     * ist.
     */
    public function test_an_unknown_attribute_is_rejected(): void
    {
        [$user, $organization, $project] = $this->project();

        $this->actingAs($user)
            ->post($this->path($organization, $project), $this->payload([
                'matchers' => [['attribute' => 'error.typ', 'pattern' => '*']],
            ]))
            ->assertSessionHasErrors('matchers.0.attribute');
    }

    /**
     * Marken lassen sich nicht aufzählen — welche ein Projekt setzt, weiß nur
     * die überwachte Anwendung.
     */
    public function test_a_tag_attribute_is_accepted(): void
    {
        [$user, $organization, $project] = $this->project();

        $this->actingAs($user)
            ->post($this->path($organization, $project), $this->payload([
                'matchers' => [['attribute' => 'tags.mandant', 'pattern' => 'gross-*']],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, FingerprintRule::query()->count());
    }

    /**
     * Eine Regel, die nur `{{ default }}` setzt, tut dasselbe wie das
     * Standardverfahren — sieht aber so aus, als täte sie etwas.
     */
    public function test_a_rule_that_only_repeats_the_default_is_rejected(): void
    {
        [$user, $organization, $project] = $this->project();

        $this->actingAs($user)
            ->post($this->path($organization, $project), $this->payload(['fingerprint' => ['{{ default }}']]))
            ->assertSessionHasErrors('fingerprint');
    }

    public function test_a_rule_can_be_updated_disabled_and_deleted(): void
    {
        [$user, $organization, $project] = $this->project();

        $rule = FingerprintRule::factory()->for($project)->create();
        $path = $this->path($organization, $project)."/{$rule->id}";

        $this->actingAs($user)
            ->patch($path, $this->payload(['name' => 'Neuer Name']))
            ->assertRedirect();

        $this->assertSame('Neuer Name', $rule->refresh()->name);

        $this->actingAs($user)->post($path.'/zustand')->assertRedirect();
        $this->assertFalse($rule->refresh()->is_active);

        $this->actingAs($user)->delete($path)->assertRedirect();
        $this->assertSame(0, FingerprintRule::query()->count());
    }

    /**
     * Eine Regel ist nur über ihr eigenes Projekt erreichbar — sonst ließe sich
     * das Grouping eines fremden Projekts über den eigenen Pfad verstellen.
     */
    public function test_a_rule_of_another_project_is_not_reachable(): void
    {
        [$user, $organization, $project] = $this->project();

        $other = Project::factory()->for($organization)->create(['slug' => 'anderes']);
        $rule = FingerprintRule::factory()->for($other)->create();

        $this->actingAs($user)
            ->patch($this->path($organization, $project)."/{$rule->id}", $this->payload())
            ->assertNotFound();
    }

    /**
     * Die Obergrenze ist keine Sparsamkeit: jede Regel wird bei jeder Meldung
     * geprüft, und ein unbemerkt wachsendes Regelwerk hält irgendwann die
     * Auswertung an.
     */
    public function test_a_project_cannot_exceed_the_rule_limit(): void
    {
        [$user, $organization, $project] = $this->project();

        FingerprintRule::factory()->for($project)->count(FingerprintRule::MAX_PER_PROJECT)->create();

        $this->actingAs($user)
            ->post($this->path($organization, $project), $this->payload())
            ->assertSessionHasErrors('name');

        $this->assertSame(FingerprintRule::MAX_PER_PROJECT, FingerprintRule::query()->count());
    }
}
