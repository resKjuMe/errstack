<?php

namespace Tests\Feature\Projects;

use App\Enums\OrganizationRole;
use App\Http\Requests\SamplingRuleRequest;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SamplingRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Die Pflege der projektweiten Stichproben-Regeln.
 *
 * Der Unterschied zu den Fingerprint-Regeln ist keine Förmlichkeit, und deshalb
 * steht er hier in Prüfungen: eine falsche Gruppierung lässt sich durch eine
 * erneute Auswertung heilen, eine zu niedrige Quote nicht. Was ausgesiebt wurde,
 * ist weg — auch aus den Rohdaten. Entsprechend prüft dieser Test zwei Dinge:
 * wer eine Regel schreiben darf, und dass sich keine Regel speichern lässt, die
 * mehr wegwirft, als jemand gemeint hat.
 */
class SamplingRuleTest extends TestCase
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
        return "/einstellungen/organisationen/{$organization->slug}/projekte/{$project->slug}/stichproben";
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Gesundheitsprüfung ausdünnen',
            'transaction_name' => 'GET /health',
            'sample_rate' => 0.01,
        ], $overrides);
    }

    public function test_the_page_lists_the_rules_of_the_project(): void
    {
        [$user, $organization, $project] = $this->project();

        SamplingRule::factory()->for($project)->create(['name' => 'Abrechnung']);

        $this->actingAs($user)
            ->get($this->path($organization, $project))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('projects/Sampling')
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

        $this->assertSame(0, SamplingRule::query()->count());
    }

    public function test_a_rule_can_be_created(): void
    {
        [$user, $organization, $project] = $this->project();

        $this->actingAs($user)
            ->post($this->path($organization, $project), $this->payload())
            ->assertRedirect();

        $rule = SamplingRule::query()->sole();

        $this->assertSame($project->id, $rule->project_id);
        $this->assertSame('GET /health', $rule->transaction_name);
        $this->assertEqualsWithDelta(0.01, $rule->sample_rate, 0.000001);
        $this->assertSame(1, $rule->minimum_per_window);
        $this->assertTrue($rule->is_active);
    }

    /**
     * Eine Regel ohne Bedingung ist erlaubt — sie ist die Vorgabe des Projekts.
     * Anders als beim Grouping, wo dieselbe Regel das ganze Projekt in eine
     * Gruppe zöge, ist „behalte von allem 10 %" hier eine sinnvolle Angabe.
     */
    public function test_a_rule_without_a_condition_is_the_project_default(): void
    {
        [$user, $organization, $project] = $this->project();

        $this->actingAs($user)
            ->post($this->path($organization, $project), [
                'name' => 'Vorgabe',
                'transaction_name' => '',
                'environment' => '',
                'release' => '',
                'op' => '',
                'sample_rate' => 0.1,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $rule = SamplingRule::query()->sole();

        // Die leeren Felder eines Formulars stehen als `null` in der Ablage und
        // nicht als leere Zeichenkette: zwei Schreibweisen für „keine Bedingung"
        // wären eine Fehlerquelle in jeder Abfrage darauf.
        foreach (SamplingRule::CONDITIONS as $field) {
            $this->assertNull($rule->{$field});
        }
    }

    /**
     * Neue Regeln kommen ans Ende. Vorne einzureihen wäre hier gefährlicher als
     * beim Grouping: eine neue Regel ohne Bedingung würde sonst die Quote aller
     * bestehenden übernehmen.
     */
    public function test_a_new_rule_goes_to_the_end(): void
    {
        [$user, $organization, $project] = $this->project();

        SamplingRule::factory()->for($project)->at(7)->create();

        $this->actingAs($user)
            ->post($this->path($organization, $project), $this->payload(['name' => 'zweite']))
            ->assertRedirect();

        $this->assertSame(8, SamplingRule::query()->where('name', 'zweite')->sole()->position);
    }

    public function test_a_rule_can_be_changed_disabled_and_deleted(): void
    {
        [$user, $organization, $project] = $this->project();

        $rule = SamplingRule::factory()->for($project)->create();
        $path = $this->path($organization, $project)."/{$rule->id}";

        $this->actingAs($user)
            ->patch($path, $this->payload(['name' => 'strenger', 'sample_rate' => 0.5, 'position' => 3]))
            ->assertRedirect();

        $rule->refresh();

        $this->assertSame('strenger', $rule->name);
        $this->assertEqualsWithDelta(0.5, $rule->sample_rate, 0.000001);
        $this->assertSame(3, $rule->position);

        $this->actingAs($user)->post($path.'/zustand')->assertRedirect();
        $this->assertFalse($rule->refresh()->is_active);

        $this->actingAs($user)->delete($path)->assertRedirect();
        $this->assertSame(0, SamplingRule::query()->count());
    }

    /**
     * Eine Regel eines fremden Projekts ist über dieses nicht erreichbar —
     * `scopeBindings` in den Routen sorgt dafür.
     */
    public function test_a_rule_of_another_project_is_out_of_reach(): void
    {
        [$user, $organization, $project] = $this->project();

        $other = Project::factory()->for($organization)->create(['name' => 'Anderes', 'slug' => 'anderes']);
        $rule = SamplingRule::factory()->for($other)->create();

        $this->actingAs($user)
            ->delete($this->path($organization, $project)."/{$rule->id}")
            ->assertNotFound();

        $this->assertSame(1, SamplingRule::query()->count());
    }

    /**
     * Eine Quote von null wäre kein Aussieben, sondern ein Abschalten — und
     * dafür gibt es das Abschalten. Sie ließe außerdem keine Hochrechnung zu:
     * aus nichts wird nichts.
     */
    public function test_a_rate_of_zero_is_rejected(): void
    {
        [$user, $organization, $project] = $this->project();

        $this->actingAs($user)
            ->post($this->path($organization, $project), $this->payload(['sample_rate' => 0]))
            ->assertSessionHasErrors('sample_rate');

        $this->actingAs($user)
            ->post($this->path($organization, $project), $this->payload(['sample_rate' => 1.5]))
            ->assertSessionHasErrors('sample_rate');

        $this->assertSame(0, SamplingRule::query()->count());
    }

    /**
     * Die Mindestquote ist die Zusage an seltene Vorgänge und keine zweite Quote.
     * Wer „mindestens hunderttausend je Minute" einträgt, hat die Stichprobe
     * abgeschafft, ohne es zu bemerken.
     */
    public function test_an_unbounded_minimum_is_rejected(): void
    {
        [$user, $organization, $project] = $this->project();

        $this->actingAs($user)
            ->post($this->path($organization, $project), $this->payload([
                'minimum_per_window' => SamplingRuleRequest::MAX_MINIMUM_PER_WINDOW + 1,
            ]))
            ->assertSessionHasErrors('minimum_per_window');

        $this->assertSame(0, SamplingRule::query()->count());
    }

    public function test_more_rules_than_allowed_are_refused(): void
    {
        [$user, $organization, $project] = $this->project();

        SamplingRule::factory()->for($project)->count(SamplingRule::MAX_PER_PROJECT)->create();

        $this->actingAs($user)
            ->post($this->path($organization, $project), $this->payload())
            ->assertSessionHasErrors('name');

        $this->assertSame(SamplingRule::MAX_PER_PROJECT, SamplingRule::query()->count());
    }
}
