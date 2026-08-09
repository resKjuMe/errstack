<?php

namespace Tests\Feature\Dashboards;

use App\Enums\OrganizationRole;
use App\Enums\WidgetType;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Support\Dashboards\DashboardLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kacheln: anlegen, ändern, löschen — und die Anordnung, die das Verschieben
 * hinterlässt.
 *
 * Das ist die Zusage der Aufgabe, an der man es sofort merkt, wenn sie nicht
 * gilt: „Widgets lassen sich anlegen, verschieben, vergrößern und löschen; die
 * Anordnung bleibt gespeichert."
 */
class DashboardWidgetTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{User, Organization, Project, Dashboard}
     */
    private function context(): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user)->create();
        $project = Project::factory()->for($organization)->create(['slug' => 'webshop']);
        $dashboard = Dashboard::factory()->for($organization)->for($user)->create();

        $user->switchOrganization($organization);

        return [$user, $organization, $project, $dashboard];
    }

    /**
     * Eine neue Kachel speichert ihre **Abfrage** — das ist die Zusage der
     * Aufgabe, und sie ist hier nachprüfbar: in der Zeile steht, was gefragt
     * wird, und kein Ergebnis.
     */
    public function test_a_widget_stores_its_query(): void
    {
        [$user, $organization, , $dashboard] = $this->context();

        $this->actingAs($user)
            ->post(route('dashboards.widgets.store', [$organization, $dashboard]), [
                'title' => 'Fehler nach Release',
                'type' => WidgetType::Line->value,
                'dataset' => 'errors',
                'fields' => ['release'],
                'metrics' => ['count()'],
                'q' => 'level:error',
                'limit' => 5,
                'interval' => '1h',
            ])
            ->assertRedirect();

        $widget = DashboardWidget::query()->firstOrFail();

        $this->assertSame('Fehler nach Release', $widget->title);
        $this->assertSame(WidgetType::Line, $widget->type);
        $this->assertSame([
            'dataset' => 'errors',
            'fields' => ['release'],
            'metrics' => ['count()'],
            'q' => 'level:error',
            'sort' => '',
            'limit' => 5,
            'interval' => '1h',
        ], $widget->query);
    }

    /**
     * Ohne Angabe landet eine Kachel unter allem, was schon da ist — dort, wo
     * man sie nach dem Anlegen sucht.
     */
    public function test_a_new_widget_lands_below_the_existing_ones(): void
    {
        [$user, $organization, , $dashboard] = $this->context();

        DashboardWidget::factory()->for($dashboard)->create(['x' => 0, 'y' => 0, 'height' => 4]);

        $this->actingAs($user)
            ->post(route('dashboards.widgets.store', [$organization, $dashboard]), [
                'title' => 'Zweite',
                'type' => WidgetType::Table->value,
            ])
            ->assertRedirect();

        $widget = DashboardWidget::query()->where('title', 'Zweite')->firstOrFail();

        $this->assertSame(4, $widget->y);
    }

    /**
     * Die Anordnung bleibt gespeichert: geschickt wird das ganze Raster, und
     * danach steht es in der Datenbank.
     */
    public function test_the_arrangement_is_saved(): void
    {
        [$user, $organization, , $dashboard] = $this->context();

        $first = DashboardWidget::factory()->for($dashboard)->create(['x' => 0, 'y' => 0]);
        $second = DashboardWidget::factory()->for($dashboard)->create(['x' => 6, 'y' => 0]);

        $this->actingAs($user)
            ->patch(route('dashboards.widgets.layout', [$organization, $dashboard]), [
                'widgets' => [
                    ['id' => $first->id, 'x' => 6, 'y' => 2, 'width' => 6, 'height' => 4],
                    ['id' => $second->id, 'x' => 0, 'y' => 0, 'width' => 4, 'height' => 6],
                ],
            ])
            ->assertRedirect();

        $first->refresh();
        $second->refresh();

        $this->assertSame([6, 2, 6, 4], [$first->x, $first->y, $first->width, $first->height]);
        $this->assertSame([0, 0, 4, 6], [$second->x, $second->y, $second->width, $second->height]);
    }

    /**
     * Eine Lage, die über den Rand hinausragt, wird hineingeschoben statt
     * abgewiesen — sonst verschwände die Kachel im Browser.
     */
    public function test_a_placement_beyond_the_grid_is_pushed_back_in(): void
    {
        [$user, $organization, , $dashboard] = $this->context();

        $widget = DashboardWidget::factory()->for($dashboard)->create();

        $this->actingAs($user)
            ->patch(route('dashboards.widgets.layout', [$organization, $dashboard]), [
                'widgets' => [
                    ['id' => $widget->id, 'x' => 11, 'y' => 0, 'width' => 8, 'height' => 4],
                ],
            ])
            ->assertRedirect();

        $widget->refresh();

        $this->assertSame(DashboardLayout::COLUMNS - 8, $widget->x);
        $this->assertSame(8, $widget->width);
    }

    /**
     * Kacheln eines anderen Dashboards werden übergangen und nicht abgewiesen:
     * ein Reiter, der noch eine gelöschte Kachel kennt, soll die Anordnung der
     * übrigen nicht verlieren.
     */
    public function test_foreign_widgets_in_a_layout_are_ignored(): void
    {
        [$user, $organization, , $dashboard] = $this->context();

        $mine = DashboardWidget::factory()->for($dashboard)->create(['x' => 0, 'y' => 0]);
        $other = DashboardWidget::factory()
            ->for(Dashboard::factory()->for($organization)->for($user))
            ->create(['x' => 0, 'y' => 0]);

        $this->actingAs($user)
            ->patch(route('dashboards.widgets.layout', [$organization, $dashboard]), [
                'widgets' => [
                    ['id' => $mine->id, 'x' => 2, 'y' => 1, 'width' => 6, 'height' => 4],
                    ['id' => $other->id, 'x' => 9, 'y' => 9, 'width' => 3, 'height' => 3],
                ],
            ])
            ->assertRedirect();

        $this->assertSame(2, $mine->refresh()->x);
        $this->assertSame(0, $other->refresh()->x);
    }

    public function test_a_widget_can_be_changed_and_deleted(): void
    {
        [$user, $organization, , $dashboard] = $this->context();

        $widget = DashboardWidget::factory()->for($dashboard)->create(['title' => 'Alt']);

        $this->actingAs($user)
            ->patch(route('dashboards.widgets.update', [$organization, $dashboard, $widget]), [
                'title' => 'Neu',
                'type' => WidgetType::BigNumber->value,
                'dataset' => 'errors',
                'metrics' => ['count()'],
            ])
            ->assertRedirect();

        $widget->refresh();

        $this->assertSame('Neu', $widget->title);
        $this->assertSame(WidgetType::BigNumber, $widget->type);

        $this->actingAs($user)
            ->delete(route('dashboards.widgets.destroy', [$organization, $dashboard, $widget]))
            ->assertRedirect();

        $this->assertSame(0, DashboardWidget::query()->count());
    }

    /**
     * Die eigene Sicht einer Kachel wird gespeichert — und leere Angaben werden
     * zu „nichts" und nicht zu einem leeren Objekt.
     */
    public function test_overrides_are_stored_only_when_they_say_something(): void
    {
        [$user, $organization, $project, $dashboard] = $this->context();

        $this->actingAs($user)
            ->post(route('dashboards.widgets.store', [$organization, $dashboard]), [
                'title' => 'Ohne eigene Sicht',
                'type' => WidgetType::Table->value,
                'overrides' => ['period' => '', 'environment' => '', 'project' => ''],
            ])
            ->assertRedirect();

        $this->assertNull(DashboardWidget::query()->where('title', 'Ohne eigene Sicht')->firstOrFail()->overrides);

        $this->actingAs($user)
            ->post(route('dashboards.widgets.store', [$organization, $dashboard]), [
                'title' => 'Mit eigener Sicht',
                'type' => WidgetType::Table->value,
                'overrides' => ['period' => '7d', 'project' => $project->slug],
            ])
            ->assertRedirect();

        $overrides = DashboardWidget::query()->where('title', 'Mit eigener Sicht')->firstOrFail()->widgetOverrides();

        $this->assertSame('7d', $overrides->period?->value);
        $this->assertSame($project->slug, $overrides->projectSlug);
    }

    /**
     * An einem fremden Dashboard verschiebt niemand Kacheln — auch nicht an
     * einem freigegebenen.
     */
    public function test_only_the_owner_may_change_widgets(): void
    {
        [$user, $organization] = $this->context();
        $owner = User::factory()->create();
        $organization->setRole($owner, OrganizationRole::Member);

        $foreign = Dashboard::factory()->for($organization)->for($owner)->shared()->create();
        $widget = DashboardWidget::factory()->for($foreign)->create();

        $this->actingAs($user)
            ->patch(route('dashboards.widgets.layout', [$organization, $foreign]), [
                'widgets' => [['id' => $widget->id, 'x' => 1, 'y' => 1, 'width' => 4, 'height' => 4]],
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->delete(route('dashboards.widgets.destroy', [$organization, $foreign, $widget]))
            ->assertForbidden();
    }
}
