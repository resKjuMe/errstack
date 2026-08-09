<?php

namespace App\Http\Controllers;

use App\Http\Requests\UptimeMonitorRequest;
use App\Jobs\CheckUptimeMonitor;
use App\Models\Organization;
use App\Models\Project;
use App\Models\UptimeMonitor;
use App\Support\UptimeMonitorData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Überwachte Ziele eines Projekts: anlegen, einstellen, abschalten, löschen —
 * und der Zustand samt Verfügbarkeitsquote, Antwortzeit-Verlauf und Ausfällen.
 *
 * Ansehen darf jedes Mitglied, ändern nur die Verwaltung (ProjectPolicy). Der
 * Unterschied ist hier noch wichtiger als bei den Cronjobs: die Seite ist die
 * Antwort auf „ist die Anwendung gerade erreichbar?", und diese Frage stellt
 * sich während einer Störung jeder.
 *
 * **Geprüft wird hier nichts.** Der Controller schreibt Einstellungen; die
 * Prüfungen laufen ausschließlich in der Warteschlange
 * ({@see CheckUptimeMonitor}). Eine Prüfung „auf Knopfdruck" wäre
 * genau der Web-Request, den die Aufgabe ausschließt — und sie hinge an einem
 * Ziel, das gerade nicht antwortet.
 */
class UptimeMonitorController extends Controller
{
    public function index(Request $request, Organization $organization, Project $project): InertiaResponse
    {
        Gate::authorize('view', $project);

        return Inertia::render('projects/Uptime', UptimeMonitorData::index($project, $request->user()));
    }

    public function store(UptimeMonitorRequest $request, Organization $organization, Project $project): RedirectResponse
    {
        Gate::authorize('manageUptime', $project);

        $monitor = UptimeMonitor::createFor(
            project: $project,
            name: (string) $request->validated('name'),
            attributes: $request->safe()->except('name'),
        );

        return back()->with('status', __('uptime.flash.created', ['name' => $monitor->name]));
    }

    public function update(UptimeMonitorRequest $request, Organization $organization, Project $project, UptimeMonitor $uptimeMonitor): RedirectResponse
    {
        Gate::authorize('manageUptime', $project);

        $uptimeMonitor->fill($request->validated());

        // Ändert sich der Takt, muss die Fälligkeit neu gerechnet werden — sonst
        // wartet der Sweep weiter auf den alten Zeitpunkt. Bei einer Verkürzung
        // wäre das eine Einstellung, die erst nach dem alten, längeren Takt
        // greift; bei einer Verlängerung eine Prüfung zu viel.
        if ($uptimeMonitor->isDirty('interval_seconds')) {
            $uptimeMonitor->scheduleNextCheck();
        }

        $uptimeMonitor->save();

        return back()->with('status', __('uptime.flash.updated', ['name' => $uptimeMonitor->name]));
    }

    /**
     * An- und abschalten.
     *
     * Ein abgeschalteter Monitor bleibt samt Verlauf und Ausfällen stehen,
     * prüft aber nicht mehr — der Weg, ein Ziel für eine geplante Wartung
     * stillzulegen, ohne seine Einstellungen zu verlieren.
     *
     * Beim Wiedereinschalten wird sofort geprüft und die Fehlerserie
     * zurückgesetzt: die Zähler stammen aus der Zeit vor der Wartung und würden
     * sonst nach der ersten Prüfung einen Ausfall auslösen, den es nicht gibt.
     */
    public function toggle(Organization $organization, Project $project, UptimeMonitor $uptimeMonitor): RedirectResponse
    {
        Gate::authorize('manageUptime', $project);

        $uptimeMonitor->is_active = ! $uptimeMonitor->is_active;

        if ($uptimeMonitor->is_active) {
            $uptimeMonitor->consecutive_failures = 0;
            $uptimeMonitor->next_check_at = now();
        }

        $uptimeMonitor->save();

        return back()->with('status', __(
            $uptimeMonitor->is_active ? 'uptime.flash.enabled' : 'uptime.flash.disabled',
            ['name' => $uptimeMonitor->name],
        ));
    }

    public function destroy(Organization $organization, Project $project, UptimeMonitor $uptimeMonitor): RedirectResponse
    {
        Gate::authorize('manageUptime', $project);

        $name = $uptimeMonitor->name;
        $uptimeMonitor->delete();

        return back()->with('status', __('uptime.flash.deleted', ['name' => $name]));
    }
}
