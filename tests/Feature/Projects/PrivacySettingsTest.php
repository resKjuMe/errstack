<?php

namespace Tests\Feature\Projects;

use App\Enums\OrganizationRole;
use App\Enums\ScrubRuleType;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ScrubRule;
use App\Models\User;
use App\Support\Ingest\Scrubbing\Scrubber;
use App\Support\PrivacyData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Ingest\ScrubbingTest;
use Tests\TestCase;

/**
 * Die Datenschutz-Seiten: Schalter, eigene Regeln, geerbte Regeln, Vorschau.
 *
 * Geprüft wird hier die Bedienung, nicht die Wirkung — was das Scrubbing
 * entfernt, steht in {@see ScrubbingTest}. Die beiden
 * Zusagen, um die es hier geht: eine Regel lässt sich auf beiden Ebenen pflegen,
 * und wer ein Projekt nicht verwalten darf, kann seine Datenschutz-Einstellungen
 * ansehen, aber nicht ändern.
 */
class PrivacySettingsTest extends TestCase
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
        return "/einstellungen/organisationen/{$organization->slug}/projekte/{$project->slug}/datenschutz";
    }

    public function test_the_page_shows_the_options_and_the_built_in_rules(): void
    {
        [$owner, $organization, $project] = $this->project();

        $this->actingAs($owner)->get($this->path($organization, $project))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('privacy/Index')
                ->where('scope', 'project')
                ->where('permissions.manage', true)
                ->where('options.scrub_ip_addresses', false)
                ->where('filteredMarker', Scrubber::FILTERED)
                // Die Zusage „ohne Konfiguration geschützt" ist nur so viel wert,
                // wie sie nachprüfbar ist — die Liste gehört auf die Seite.
                ->has('defaults.fields')
                ->has('defaults.patterns')
            );
    }

    public function test_the_options_survive_a_reload(): void
    {
        [$owner, $organization, $project] = $this->project();
        $path = $this->path($organization, $project);

        $this->actingAs($owner)->patch($path, [
            'scrub_ip_addresses' => true,
            'scrub_user_data' => true,
            'scrub_attachments' => false,
        ])->assertSessionHasNoErrors();

        $project->refresh();

        $this->assertTrue($project->scrub_ip_addresses);
        $this->assertTrue($project->scrub_user_data);
        $this->assertFalse($project->scrub_attachments);

        $this->actingAs($owner)->get($path)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('options.scrub_ip_addresses', true)
                ->where('options.scrub_user_data', true)
            );
    }

    public function test_a_project_rule_can_be_created_changed_and_deleted(): void
    {
        [$owner, $organization, $project] = $this->project();

        $this->actingAs($owner)->post($this->path($organization, $project).'/regeln', [
            'type' => 'field',
            'expression' => 'kundennummer',
            'path' => 'request.data',
            'is_active' => true,
        ])->assertSessionHasNoErrors();

        $rule = ScrubRule::query()->sole();

        $this->assertSame($project->id, $rule->project_id);
        $this->assertSame($organization->id, $rule->organization_id);
        $this->assertSame(ScrubRuleType::Field, $rule->type);
        $this->assertSame('request.data', $rule->path);

        $this->actingAs($owner)->patch("/einstellungen/datenschutz-regeln/{$rule->id}", [
            'type' => 'field',
            'expression' => 'kunden_*',
            'path' => '',
            'is_active' => false,
        ])->assertSessionHasNoErrors();

        $rule->refresh();

        $this->assertSame('kunden_*', $rule->expression);
        $this->assertNull($rule->path, 'Ein leerer Abschnitt heißt „ganze Meldung".');
        $this->assertFalse($rule->is_active);

        $this->actingAs($owner)->delete("/einstellungen/datenschutz-regeln/{$rule->id}")
            ->assertSessionHasNoErrors();

        $this->assertSame(0, ScrubRule::query()->count());
    }

    public function test_an_organization_rule_has_no_project_and_shows_up_at_the_project(): void
    {
        [$owner, $organization, $project] = $this->project();

        $this->actingAs($owner)->post("/einstellungen/organisationen/{$organization->slug}/datenschutz/regeln", [
            'type' => 'pattern',
            'expression' => 'K-\d{6}',
            'path' => null,
            'is_active' => true,
        ])->assertSessionHasNoErrors();

        $this->assertNull(ScrubRule::query()->sole()->project_id);

        $this->actingAs($owner)->get($this->path($organization, $project))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('rules', 0)
                ->has('inheritedRules', 1)
                ->where('inheritedRules.0.expression', 'K-\d{6}')
            );
    }

    /**
     * Ein Muster, das sich nicht übersetzen lässt, wird abgewiesen. Angenommen
     * wäre es eine Regel, die nie greift — und niemand erfährt davon.
     */
    public function test_an_invalid_pattern_is_rejected(): void
    {
        [$owner, $organization, $project] = $this->project();

        $this->actingAs($owner)->post($this->path($organization, $project).'/regeln', [
            'type' => 'pattern',
            'expression' => '([unvollständig',
            'is_active' => true,
        ])->assertSessionHasErrors('expression');

        $this->assertSame(0, ScrubRule::query()->count());
    }

    public function test_a_section_that_is_not_a_path_is_rejected(): void
    {
        [$owner, $organization, $project] = $this->project();

        $this->actingAs($owner)->post($this->path($organization, $project).'/regeln', [
            'type' => 'field',
            'expression' => 'kundennummer',
            'path' => 'request data[*]',
            'is_active' => true,
        ])->assertSessionHasErrors('path');
    }

    /**
     * Die Vorschau rechnet mit denselben Einstellungen wie die Aufnahme — sonst
     * wäre sie eine Vermutung.
     */
    public function test_the_preview_shows_what_the_rules_would_remove(): void
    {
        [$owner, $organization, $project] = $this->project();
        $project->update(['scrub_ip_addresses' => true]);

        ScrubRule::factory()->forProject($project)->create(['expression' => 'kundennummer']);

        $this->actingAs($owner)
            ->post($this->path($organization, $project).'/vorschau', [
                'sample' => (string) json_encode(PrivacyData::sample()),
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('scrubPreview', function (array $preview): bool {
                $this->assertContains('request.data.password', $preview['paths']);
                $this->assertContains('request.data.kundennummer', $preview['paths']);
                $this->assertContains('user.ip_address', $preview['paths']);
                $this->assertStringNotContainsString('geheim123', (string) $preview['event']);

                return true;
            });
    }

    public function test_the_preview_refuses_something_that_is_not_an_event(): void
    {
        [$owner, $organization, $project] = $this->project();
        $path = $this->path($organization, $project).'/vorschau';

        $this->actingAs($owner)->post($path, ['sample' => 'kein json'])
            ->assertSessionHasErrors('sample');

        // Gültiges JSON, aber keine Meldung: `json` allein lässt das durch.
        $this->actingAs($owner)->post($path, ['sample' => '42'])
            ->assertSessionHasErrors('sample');
    }

    public function test_members_may_read_the_settings_but_not_change_them(): void
    {
        [$member, $organization, $project] = $this->project(OrganizationRole::Member);
        $path = $this->path($organization, $project);

        $this->actingAs($member)->get($path)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('permissions.manage', false));

        $this->actingAs($member)->patch($path, [
            'scrub_ip_addresses' => true,
            'scrub_user_data' => false,
            'scrub_attachments' => false,
        ])->assertForbidden();

        $this->actingAs($member)->post($path.'/regeln', [
            'type' => 'field',
            'expression' => 'irgendwas',
            'is_active' => true,
        ])->assertForbidden();

        $this->assertFalse($project->refresh()->scrub_ip_addresses);
        $this->assertSame(0, ScrubRule::query()->count());
    }

    /**
     * Eine Regel eines fremden Projekts lässt sich nicht über die eigene
     * Organisation ändern — die Bindung läuft über die Beziehung und nicht über
     * die Nummer in der Adresse.
     */
    public function test_a_rule_of_a_foreign_organization_cannot_be_touched(): void
    {
        [$owner] = $this->project();

        $foreign = ScrubRule::factory()->create(['expression' => 'fremd']);

        $this->actingAs($owner)->patch("/einstellungen/datenschutz-regeln/{$foreign->id}", [
            'type' => 'field',
            'expression' => 'gekapert',
            'is_active' => true,
        ])->assertForbidden();

        $this->assertSame('fremd', $foreign->refresh()->expression);
    }

    public function test_the_organization_page_lists_only_its_own_rules(): void
    {
        [$owner, $organization, $project] = $this->project();

        ScrubRule::factory()->create(['organization_id' => $organization->id, 'expression' => 'weit']);
        ScrubRule::factory()->forProject($project)->create(['expression' => 'eng']);

        $this->actingAs($owner)->get("/einstellungen/organisationen/{$organization->slug}/datenschutz")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('privacy/Index')
                ->where('scope', 'organization')
                ->where('options', null)
                ->has('rules', 1)
                ->where('rules.0.expression', 'weit')
            );
    }
}
