<?php

namespace App\Support\Dashboards;

use App\Enums\FilterPeriod;
use App\Enums\WidgetType;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\Project;
use App\Models\User;
use App\Support\Filters\GlobalFilter;
use Illuminate\Support\Facades\Gate;

/**
 * Dashboards, wie die Oberfläche sie braucht.
 *
 * **Was eine Kachel liefert, ist ihre Abfrage — nicht ihr Inhalt.** Die Zahlen
 * holt der Browser danach je Kachel über eine eigene Adresse
 * ({@see WidgetData}); hier steht nur, *was* gefragt wird und *wo* die Kachel
 * liegt. Dieselbe Trennung wie in der Datenbank, und aus demselben Grund.
 *
 * **Die Rechte stehen mit dabei.** Ob jemand ändern darf, entscheidet die
 * Policy — und die Oberfläche soll dieselbe Antwort zeigen, statt sie aus
 * „gehört mir" nachzubauen. Ein Bearbeiten-Knopf, der zu einer Absage führt,
 * ist schlimmer als keiner.
 */
final class DashboardData
{
    /**
     * Ein Eintrag der Liste.
     *
     * @return array<string, mixed>
     */
    public static function summary(Dashboard $dashboard, User $viewer): array
    {
        return [
            'id' => $dashboard->id,
            'name' => $dashboard->name,
            'description' => $dashboard->description,
            'shared' => $dashboard->shared,
            'template' => $dashboard->template,
            'widgets' => (int) ($dashboard->widgets_count ?? $dashboard->widgets()->count()),
            'owner' => $dashboard->user?->name ?? '',
            'own' => $dashboard->user_id === $viewer->id,
            'updatedAt' => $dashboard->updated_at?->toIso8601ZuluString(),
            // Die Adressen kommen vom Server und werden nicht im Browser
            // zusammengesetzt: die Routen kennt Laravel, und eine im Browser
            // gebaute Adresse ist die Fassung von heute in einer Anwendung von
            // morgen. Die Organisation steht dabei nicht dabei — sie ist als
            // Vorbelegung hinterlegt (siehe ResolveOrganization), wie bei jeder
            // anderen Verlinkung im Code.
            'href' => route('dashboards.show', $dashboard),
            'duplicateHref' => route('dashboards.duplicate', $dashboard),
            'canUpdate' => Gate::forUser($viewer)->allows('update', $dashboard),
            'canDelete' => Gate::forUser($viewer)->allows('delete', $dashboard),
        ];
    }

    /**
     * Das geöffnete Dashboard.
     *
     * @return array<string, mixed>
     */
    public static function detail(Dashboard $dashboard, User $viewer): array
    {
        return self::summary($dashboard, $viewer) + [
            'full' => $dashboard->widgets()->count() >= DashboardLayout::MAX_WIDGETS,
            'widgetsHref' => route('dashboards.widgets.store', $dashboard),
            'layoutHref' => route('dashboards.widgets.layout', $dashboard),
        ];
    }

    /**
     * Eine Kachel: Überschrift, Art, Lage — und ihre Abfrage in genau der Form,
     * in der das Formular sie wieder annimmt.
     *
     * @return array<string, mixed>
     */
    public static function widget(DashboardWidget $widget, GlobalFilter $filter): array
    {
        return [
            'id' => $widget->id,
            'title' => $widget->title,
            'type' => $widget->type->value,
            'query' => $widget->widgetQuery()->toArray(),
            'overrides' => $widget->widgetOverrides()->toArray(),
            'x' => $widget->x,
            'y' => $widget->y,
            'width' => $widget->width,
            'height' => $widget->height,
            'href' => route('dashboards.widgets.update', [$widget->dashboard_id, $widget]),
            // Die Adresse der Zahlen trägt den Zustand der Filterleiste bei
            // sich: die Kachel fragt mit genau dem Ausschnitt, den die Seite
            // zeigt. Wechselt er, kommt die Seite neu — und mit ihr diese
            // Adresse.
            'dataHref' => route('dashboards.widgets.data', [$widget->dashboard_id, $widget] + $filter->formValues()),
        ];
    }

    /**
     * Die Regeln des Rasters und die Auswahl der Darstellungsarten — beides vom
     * Server, damit die Oberfläche nichts anbietet, was der Server zurechtrückt.
     *
     * @return array<string, mixed>
     */
    public static function grid(): array
    {
        return [
            'columns' => DashboardLayout::COLUMNS,
            'minWidth' => DashboardLayout::MIN_WIDTH,
            'minHeight' => DashboardLayout::MIN_HEIGHT,
            'maxHeight' => DashboardLayout::MAX_HEIGHT,
            'defaultWidth' => DashboardLayout::DEFAULT_WIDTH,
            'defaultHeight' => DashboardLayout::DEFAULT_HEIGHT,
            'maxWidgets' => DashboardLayout::MAX_WIDGETS,
            'types' => WidgetType::options(),
            'countryField' => WidgetType::COUNTRY_FIELD,
            // Die Zeiträume, die eine Kachel für sich wählen darf — dieselbe
            // Liste wie in der Filterleiste. Zwei Listen wären zwei Antworten
            // auf „was heißt letzte 7 Tage".
            'periods' => FilterPeriod::options(),
        ];
    }

    /**
     * Die Projekte, die eine Kachel für sich wählen darf.
     *
     * @return list<array{slug: string, name: string}>
     */
    public static function projectOptions(GlobalFilter $filter): array
    {
        return $filter->availableProjects
            ->map(static fn (Project $project): array => ['slug' => $project->slug, 'name' => $project->name])
            ->values()
            ->all();
    }
}
