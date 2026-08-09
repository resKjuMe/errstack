<?php

namespace Tests\Feature\Dashboards;

use App\Enums\OrganizationRole;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Support\Dashboards\DashboardLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Die Verwaltung der Dashboards: Liste, Anlegen aus einer Vorlage, Öffnen,
 * Ändern, Duplizieren, Löschen — und wer davon was darf.
 *
 * Gerechnet wird hier nichts nachgeprüft: was eine Kachel zeigt, ist Sache von
 * {@see WidgetDataTest}. Geprüft wird, dass die Sammlung als Sammlung
 * funktioniert.
 */
class DashboardPageTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_the_list_shows_own_and_shared_dashboards(): void
    {
        [$user, $organization] = $this->context();
        $other = User::factory()->create();
        $organization->setRole($other, OrganizationRole::Member);

        Dashboard::factory()->for($organization)->for($user)->create(['name' => 'Meins']);
        Dashboard::factory()->for($organization)->for($other)->shared()->create(['name' => 'Geteiltes']);
        Dashboard::factory()->for($organization)->for($other)->create(['name' => 'Fremdes']);

        $this->actingAs($user)
            ->get(route('dashboards.index', $organization))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('dashboards/Index')
                ->has('dashboards', 2)
            );
    }

    /**
     * Eine Vorlage legt ein Dashboard **samt Kacheln** an — sonst wäre sie ein
     * Name und sonst nichts.
     */
    public function test_a_template_creates_a_dashboard_with_widgets(): void
    {
        [$user, $organization] = $this->context();

        $this->actingAs($user)
            ->post(route('dashboards.store', $organization), [
                'name' => 'Mein Überblick',
                'template' => 'errors',
            ])
            ->assertRedirect();

        $dashboard = Dashboard::query()->firstOrFail();

        $this->assertSame('Mein Überblick', $dashboard->name);
        $this->assertSame('errors', $dashboard->template);
        $this->assertGreaterThan(0, $dashboard->widgets()->count());
    }

    /**
     * Mindestens drei Vorlagen sind vorhanden, und jede legt Kacheln an, die der
     * Motor auch versteht.
     */
    public function test_every_template_can_be_created(): void
    {
        [$user, $organization] = $this->context();

        $templates = $this->actingAs($user)
            ->get(route('dashboards.index', $organization))
            ->assertOk()
            ->viewData('page')['props']['templates'];

        $this->assertGreaterThanOrEqual(3, count($templates));

        foreach ($templates as $template) {
            $this->actingAs($user)
                ->post(route('dashboards.store', $organization), [
                    'name' => 'Vorlage '.$template['value'],
                    'template' => $template['value'],
                ])
                ->assertRedirect();
        }

        $this->assertSame(count($templates), Dashboard::query()->count());
        $this->assertGreaterThan(0, DashboardWidget::query()->count());
    }

    public function test_a_dashboard_shows_its_widgets_in_reading_order(): void
    {
        [$user, $organization, $project] = $this->context();

        $dashboard = Dashboard::factory()->for($organization)->for($user)->create();

        DashboardWidget::factory()->for($dashboard)->create(['title' => 'Unten', 'x' => 0, 'y' => 4]);
        DashboardWidget::factory()->for($dashboard)->create(['title' => 'Oben rechts', 'x' => 6, 'y' => 0]);
        DashboardWidget::factory()->for($dashboard)->create(['title' => 'Oben links', 'x' => 0, 'y' => 0]);

        $this->actingAs($user)
            ->get(route('dashboards.show', [$organization, $dashboard, 'projects' => [$project->slug]]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('dashboards/Show')
                ->where('widgets.0.title', 'Oben links')
                ->where('widgets.1.title', 'Oben rechts')
                ->where('widgets.2.title', 'Unten')
                ->where('dashboard.canUpdate', true)
                ->where('grid.columns', DashboardLayout::COLUMNS)
            );
    }

    /**
     * Ein fremdes, freigegebenes Dashboard darf man ansehen — aber nicht ändern.
     */
    public function test_a_shared_dashboard_is_readable_but_not_writable(): void
    {
        [$user, $organization] = $this->context();
        $owner = User::factory()->create();
        $organization->setRole($owner, OrganizationRole::Member);

        $dashboard = Dashboard::factory()->for($organization)->for($owner)->shared()->create();

        $this->actingAs($user)
            ->get(route('dashboards.show', [$organization, $dashboard]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('dashboard.canUpdate', false));

        $this->actingAs($user)
            ->patch(route('dashboards.update', [$organization, $dashboard]), ['name' => 'Umbenannt'])
            ->assertForbidden();
    }

    public function test_a_private_dashboard_stays_private(): void
    {
        [$user, $organization] = $this->context();
        $owner = User::factory()->create();
        $organization->setRole($owner, OrganizationRole::Member);

        $dashboard = Dashboard::factory()->for($organization)->for($owner)->create();

        $this->actingAs($user)
            ->get(route('dashboards.show', [$organization, $dashboard]))
            ->assertForbidden();
    }

    /**
     * Ein Duplikat nimmt alle Kacheln samt Anordnung mit, gehört aber dem, der
     * es angelegt hat — und ist nicht freigegeben.
     */
    public function test_duplicating_copies_the_widgets_but_not_the_ownership(): void
    {
        [$user, $organization] = $this->context();
        $owner = User::factory()->create();
        $organization->setRole($owner, OrganizationRole::Member);

        $dashboard = Dashboard::factory()->for($organization)->for($owner)->shared()->create(['name' => 'Vorbild']);
        DashboardWidget::factory()->for($dashboard)->create(['title' => 'Kachel A', 'x' => 3, 'y' => 2]);

        $this->actingAs($user)
            ->post(route('dashboards.duplicate', [$organization, $dashboard]))
            ->assertRedirect();

        $copy = Dashboard::query()->where('user_id', $user->id)->firstOrFail();

        $this->assertNotSame($dashboard->id, $copy->id);
        $this->assertFalse($copy->shared);
        $this->assertSame(1, $copy->widgets()->count());

        $widget = $copy->widgets()->firstOrFail();

        $this->assertSame('Kachel A', $widget->title);
        $this->assertSame(3, $widget->x);
        $this->assertSame(2, $widget->y);
    }

    /**
     * Zweimal duplizieren geht auch — der Name ist je Konto eindeutig, und das
     * darf nicht am zweiten Versuch scheitern.
     */
    public function test_duplicating_twice_finds_a_free_name(): void
    {
        [$user, $organization] = $this->context();

        $dashboard = Dashboard::factory()->for($organization)->for($user)->create(['name' => 'Vorbild']);

        $this->actingAs($user)->post(route('dashboards.duplicate', [$organization, $dashboard]))->assertRedirect();
        $this->actingAs($user)->post(route('dashboards.duplicate', [$organization, $dashboard]))->assertRedirect();

        $this->assertSame(3, Dashboard::query()->count());
    }

    public function test_deleting_removes_the_dashboard_and_its_widgets(): void
    {
        [$user, $organization] = $this->context();

        $dashboard = Dashboard::factory()->for($organization)->for($user)->create();
        DashboardWidget::factory()->for($dashboard)->create();

        $this->actingAs($user)
            ->delete(route('dashboards.destroy', [$organization, $dashboard]))
            ->assertRedirect(route('dashboards.index', $organization));

        $this->assertSame(0, Dashboard::query()->count());
        $this->assertSame(0, DashboardWidget::query()->count());
    }

    /**
     * Zwei Dashboards desselben Kontos dürfen nicht denselben Namen tragen —
     * sonst stünde in der Liste zweimal dasselbe Wort.
     */
    public function test_the_name_is_unique_per_account(): void
    {
        [$user, $organization] = $this->context();

        Dashboard::factory()->for($organization)->for($user)->create(['name' => 'Überblick']);

        $this->actingAs($user)
            ->post(route('dashboards.store', $organization), ['name' => 'Überblick'])
            ->assertSessionHasErrors('name');
    }
}
