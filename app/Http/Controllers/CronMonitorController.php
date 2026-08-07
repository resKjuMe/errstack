<?php

namespace App\Http\Controllers;

use App\Http\Requests\CronMonitorRequest;
use App\Models\CronMonitor;
use App\Models\Organization;
use App\Models\Project;
use App\Support\CronMonitorData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Überwachte Cronjobs eines Projekts: anlegen, einstellen, abschalten, löschen —
 * und der Verlauf der letzten Ausführungen.
 *
 * Ansehen darf jedes Mitglied, ändern nur die Verwaltung (ProjectPolicy). Der
 * Unterschied ist hier wichtiger als anderswo: die Seite ist die Antwort auf
 * „ist der nächtliche Import durchgelaufen?", und diese Frage stellt sich nicht
 * nur die Verwaltung.
 */
class CronMonitorController extends Controller
{
    public function index(Request $request, Organization $organization, Project $project): InertiaResponse
    {
        Gate::authorize('view', $project);

        return Inertia::render('projects/Crons', CronMonitorData::index($project, $request->user()));
    }

    public function store(CronMonitorRequest $request, Organization $organization, Project $project): RedirectResponse
    {
        Gate::authorize('manageCrons', $project);

        $cronMonitor = CronMonitor::createFor(
            project: $project,
            name: (string) $request->validated('name'),
            attributes: $request->safe()->except('name'),
        );

        return back()->with('status', __('crons.flash.created', [
            'name' => $cronMonitor->name,
            'slug' => $cronMonitor->slug,
        ]));
    }

    public function update(CronMonitorRequest $request, Organization $organization, Project $project, CronMonitor $cronMonitor): RedirectResponse
    {
        Gate::authorize('manageCrons', $project);

        $cronMonitor->fill($request->validated());

        // Ändert sich der Zeitplan, muss der nächste Termin neu gerechnet
        // werden — sonst wartet die Prüfung weiter auf den alten und meldet
        // einen Ausfall, den es nicht gibt.
        if ($cronMonitor->isDirty(['schedule_type', 'schedule_expression', 'interval_value', 'interval_unit', 'timezone'])) {
            $cronMonitor->scheduleNextDue();
        }

        $cronMonitor->save();

        return back()->with('status', __('crons.flash.updated', ['name' => $cronMonitor->name]));
    }

    /**
     * An- und abschalten.
     *
     * Ein abgeschalteter Monitor bleibt samt Verlauf stehen, stellt aber nichts
     * mehr fest — der Weg, einen Job für eine Wartung stillzulegen, ohne seine
     * Einstellungen zu verlieren. Beim Wiedereinschalten wird der Termin neu
     * gesetzt: der Zeitplan hat in der Zwischenzeit weitergezählt, und ohne das
     * käme sofort eine ganze Reihe verpasster Läufe.
     */
    public function toggle(Organization $organization, Project $project, CronMonitor $cronMonitor): RedirectResponse
    {
        Gate::authorize('manageCrons', $project);

        $cronMonitor->is_active = ! $cronMonitor->is_active;

        if ($cronMonitor->is_active) {
            $cronMonitor->scheduleNextDue();
            $cronMonitor->consecutive_failures = 0;
            $cronMonitor->alerted_at = null;
        }

        $cronMonitor->save();

        return back()->with('status', __(
            $cronMonitor->is_active ? 'crons.flash.enabled' : 'crons.flash.disabled',
            ['name' => $cronMonitor->name],
        ));
    }

    public function destroy(Organization $organization, Project $project, CronMonitor $cronMonitor): RedirectResponse
    {
        Gate::authorize('manageCrons', $project);

        $name = $cronMonitor->name;
        $cronMonitor->delete();

        return back()->with('status', __('crons.flash.deleted', ['name' => $name]));
    }
}
