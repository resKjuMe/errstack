<?php

namespace Tests\Feature\Projects;

use App\Enums\OrganizationRole;
use App\Enums\QuotaCategory;
use App\Enums\QuotaScope;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Quota;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Die Kontingent-Seiten: je Projekt und je Organisation.
 *
 * Der wiederkehrende Punkt in diesen Prüfungen ist die Trennung von Ansehen und
 * Ändern. Ansehen darf jedes Mitglied — die Seite ist die Antwort auf „warum
 * kommt seit gestern nichts mehr an?", und die stellt sich selten die
 * Verwaltung. Ändern darf sie allein.
 */
class QuotaSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function project(OrganizationRole $role = OrganizationRole::Admin): Project
    {
        $project = Project::factory()->create();
        /** @var Organization $organization */
        $organization = $project->organization;

        $user = User::factory()->create();
        $organization->setRole($user, $role);

        $this->actingAs($user);

        return $project;
    }

    private function url(Project $project): string
    {
        return "/einstellungen/organisationen/{$project->organization->slug}/projekte/{$project->slug}/kontingente";
    }

    public function test_a_member_may_look_at_the_quotas(): void
    {
        $project = $this->project(OrganizationRole::Member);

        Quota::set(QuotaScope::Project, $project->id, QuotaCategory::Errors, 5_000, 100);

        $this->get($this->url($project))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('projects/Quotas')
                ->where('permissions.manage', false)
                ->where('categories.0.value', QuotaCategory::Errors->value)
                ->where('categories.0.perMonth', 5_000)
                ->where('categories.0.perMinute', 100));
    }

    public function test_the_administration_sets_a_quota_per_category(): void
    {
        $project = $this->project();

        $this->patch($this->url($project), [
            'quotas' => [
                QuotaCategory::Errors->value => ['per_month' => '100000', 'per_minute' => '500'],
                QuotaCategory::Transactions->value => ['per_month' => '', 'per_minute' => '50'],
            ],
        ])->assertRedirect();

        $errors = Quota::query()->where('category', QuotaCategory::Errors->value)->sole();

        $this->assertSame(QuotaScope::Project, $errors->scope);
        $this->assertSame($project->id, $errors->scope_id);
        $this->assertSame(100_000, $errors->per_month);
        $this->assertSame(500, $errors->per_minute);

        $transactions = Quota::query()->where('category', QuotaCategory::Transactions->value)->sole();

        $this->assertNull($transactions->per_month);
        $this->assertSame(50, $transactions->per_minute);
    }

    /**
     * Beide Felder leer heißt „unbegrenzt" — und dann soll auch keine Zeile
     * stehen bleiben, die nichts sagt.
     */
    public function test_clearing_both_fields_removes_the_quota(): void
    {
        $project = $this->project();

        Quota::set(QuotaScope::Project, $project->id, QuotaCategory::Errors, 1_000, 10);

        $this->patch($this->url($project), [
            'quotas' => [
                QuotaCategory::Errors->value => ['per_month' => '', 'per_minute' => ''],
            ],
        ])->assertRedirect();

        $this->assertSame(0, Quota::query()->count());
    }

    public function test_a_member_may_not_change_the_quotas(): void
    {
        $project = $this->project(OrganizationRole::Member);

        $this->patch($this->url($project), [
            'quotas' => [
                QuotaCategory::Errors->value => ['per_month' => '10', 'per_minute' => ''],
            ],
        ])->assertForbidden();

        $this->assertSame(0, Quota::query()->count());
    }

    public function test_nonsense_values_are_rejected(): void
    {
        $project = $this->project();

        $this->patch($this->url($project), [
            'quotas' => [
                QuotaCategory::Errors->value => ['per_month' => '0', 'per_minute' => 'viele'],
            ],
        ])->assertSessionHasErrors([
            'quotas.errors.per_month',
            'quotas.errors.per_minute',
        ]);
    }

    /**
     * Unbekannte Datenarten fallen still heraus — ein Tippfehler im Formular
     * legt sonst eine Zeile an, die nie wieder greift.
     */
    public function test_unknown_categories_are_ignored(): void
    {
        $project = $this->project();

        $this->patch($this->url($project), [
            'quotas' => [
                'spans' => ['per_month' => '10', 'per_minute' => ''],
            ],
        ])->assertRedirect();

        $this->assertSame(0, Quota::query()->count());
    }

    public function test_the_organization_page_shows_and_saves_its_own_quotas(): void
    {
        $project = $this->project();
        /** @var Organization $organization */
        $organization = $project->organization;

        $url = "/einstellungen/organisationen/{$organization->slug}/kontingente";

        $this->get($url)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('organizations/Quotas')
                ->where('scope', QuotaScope::Organization->value)
                ->where('permissions.manage', true));

        $this->patch($url, [
            'quotas' => [
                QuotaCategory::Replays->value => ['per_month' => '250', 'per_minute' => ''],
            ],
        ])->assertRedirect();

        $quota = Quota::query()->sole();

        $this->assertSame(QuotaScope::Organization, $quota->scope);
        $this->assertSame($organization->id, $quota->scope_id);
        $this->assertSame(250, $quota->per_month);
    }

    /**
     * Kontingente hängen über Ebene und Kennung am Datensatz und nicht über
     * einen Fremdschlüssel — ohne den Aufräum-Haken bliebe eine Grenze für eine
     * Kennung stehen, die es nicht mehr gibt.
     */
    public function test_deleting_a_project_removes_its_quotas(): void
    {
        $project = $this->project();

        Quota::set(QuotaScope::Project, $project->id, QuotaCategory::Errors, 1_000, null);

        $project->delete();

        $this->assertSame(0, Quota::query()->count());
    }
}
