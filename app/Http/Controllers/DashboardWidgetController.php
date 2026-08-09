<?php

namespace App\Http\Controllers;

use App\Http\Requests\DashboardLayoutRequest;
use App\Http\Requests\DashboardWidgetRequest;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Support\Dashboards\DashboardLayout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Kacheln anlegen, ändern, löschen — und die Anordnung nach dem Verschieben
 * festhalten.
 *
 * **Alles davon ist eine Änderung am Dashboard** und braucht deshalb das Recht
 * daran (`update`). Eine freigegebene Sammlung, an der jeder Mitleser Kacheln
 * verschieben könnte, wäre morgen eine andere Sammlung — und der Ersteller hätte
 * es nicht bemerkt.
 *
 * **Das Verschieben ist ein eigener Aufruf und keine Bearbeitung.** Es schickt
 * nur Lagen, und es schickt sie für alle Kacheln zusammen: eine Bewegung im
 * Raster ist eine Bewegung und nicht zehn.
 */
class DashboardWidgetController extends Controller
{
    public function store(DashboardWidgetRequest $request, Dashboard $dashboard): RedirectResponse
    {
        Gate::authorize('update', $dashboard);

        if (! $dashboard->hasRoomForWidget()) {
            throw ValidationException::withMessages([
                'title' => __('dashboards.errors.too_many_widgets', ['limit' => DashboardLayout::MAX_WIDGETS]),
            ]);
        }

        $placement = $request->placement() ?? DashboardLayout::normalize(
            0,
            // Ohne Angabe landet die Kachel unter allem, was schon da ist —
            // dort, wo man sie nach dem Anlegen sucht.
            DashboardLayout::nextRow($dashboard->widgets),
            DashboardLayout::DEFAULT_WIDTH,
            DashboardLayout::DEFAULT_HEIGHT,
        );

        DashboardWidget::query()->create([
            'dashboard_id' => $dashboard->id,
            'title' => $request->title(),
            'type' => $request->type(),
            'query' => $request->widgetQuery()->toArray(),
            'overrides' => self::overrides($request),
            ...$placement,
        ]);

        // Die Änderung gehört dem Dashboard: die Liste sortiert nach „zuletzt
        // geändert", und eine neue Kachel ist eine Änderung daran.
        $dashboard->touch();

        return back()->with('status', __('dashboards.flash.widget_created'));
    }

    public function update(DashboardWidgetRequest $request, Dashboard $dashboard, DashboardWidget $widget): RedirectResponse
    {
        Gate::authorize('update', $dashboard);

        $this->ensureBelongsTo($dashboard, $widget);

        $widget->update([
            'title' => $request->title(),
            'type' => $request->type(),
            'query' => $request->widgetQuery()->toArray(),
            'overrides' => self::overrides($request),
            ...($request->placement() ?? []),
        ]);

        $dashboard->touch();

        return back()->with('status', __('dashboards.flash.widget_updated'));
    }

    /**
     * Löschen braucht keine Eingabe und deshalb auch keine Prüfung einer solchen
     * — mit {@see DashboardWidgetRequest} scheiterte der Aufruf an fehlenden
     * Pflichtfeldern, die es beim Löschen gar nicht gibt.
     */
    public function destroy(Dashboard $dashboard, DashboardWidget $widget): RedirectResponse
    {
        Gate::authorize('update', $dashboard);

        $this->ensureBelongsTo($dashboard, $widget);

        $widget->delete();
        $dashboard->touch();

        return back()->with('status', __('dashboards.flash.widget_deleted'));
    }

    /**
     * Die neue Anordnung — alle Kacheln in einem Aufruf.
     *
     * Kacheln, die nicht zu diesem Dashboard gehören, werden übergangen und
     * nicht abgewiesen: ein Reiter, der noch eine gelöschte Kachel kennt, soll
     * die Anordnung der übrigen nicht verlieren.
     */
    public function layout(DashboardLayoutRequest $request, Dashboard $dashboard): RedirectResponse
    {
        Gate::authorize('update', $dashboard);

        $placements = $request->placements();

        DB::transaction(function () use ($dashboard, $placements): void {
            foreach ($dashboard->widgets as $widget) {
                $placement = $placements[$widget->id] ?? null;

                if ($placement !== null) {
                    $widget->update($placement);
                }
            }
        });

        $dashboard->touch();

        return back()->with('status', __('dashboards.flash.layout_saved'));
    }

    /**
     * Leere Angaben werden zu `null` und nicht zu einem leeren Objekt: „die
     * Kachel sagt nichts" und „die Kachel sagt nichts, aber ausdrücklich" wären
     * sonst zwei Zustände mit derselben Wirkung.
     *
     * @return array<string, string|null>|null
     */
    private static function overrides(DashboardWidgetRequest $request): ?array
    {
        $overrides = $request->overrides();

        return $overrides->isEmpty() ? null : $overrides->toArray();
    }

    /**
     * Eine Kachel eines anderen Dashboards ist hier nicht „verboten", sondern
     * nicht vorhanden: die Adresse nennt beide, und wenn sie nicht
     * zusammengehören, meint sie nichts.
     */
    private function ensureBelongsTo(Dashboard $dashboard, DashboardWidget $widget): void
    {
        if ($widget->dashboard_id !== $dashboard->id) {
            throw new NotFoundHttpException;
        }
    }
}
