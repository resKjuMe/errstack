<?php

namespace App\Http\Controllers;

use App\Enums\AlertComparison;
use App\Enums\AlertDirection;
use App\Enums\AlertMetric;
use App\Enums\AlertStatus;
use App\Http\Requests\MetricAlertRequest;
use App\Models\MetricAlert;
use App\Models\MetricAlertTransition;
use App\Models\Organization;
use App\Models\Project;
use App\Support\Alerts\MetricAlertPreview;
use App\Support\Formats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Die Schwellwert-Alarme eines Projekts: anlegen, ändern, an- und abschalten,
 * löschen — und vorher nachsehen, wo der Wert eigentlich liegt.
 *
 * Ansehen darf jedes Mitglied: welche Alarme scharf sind, ist die erste Frage,
 * wenn etwas nicht gemeldet wurde, und die stellt nicht nur die Verwaltung.
 * Ändern darf nur die Verwaltung — eine verstellte Schwelle ist eine
 * Überwachung, die leiser gedreht wurde, und das soll niemand nebenbei tun.
 */
class MetricAlertController extends Controller
{
    /**
     * Wie viele Zustandswechsel im Verlauf stehen.
     *
     * Genug, um zu sehen, ob ein Alarm flattert; wenig genug, dass die Seite
     * eine Abfrage bleibt.
     */
    private const HISTORY_LIMIT = 20;

    public function index(Organization $organization, Project $project): InertiaResponse
    {
        Gate::authorize('view', $project);

        return Inertia::render('projects/Alerts', [
            'organization' => [
                'name' => $organization->name,
                'href' => route('organizations.show', $organization),
            ],
            'project' => [
                'name' => $project->name,
                'href' => route('projects.show', [$organization, $project]),
                'alertsHref' => route('projects.alerts.store', [$organization, $project]),
                'previewHref' => route('projects.alerts.preview', [$organization, $project]),
            ],
            'alerts' => $this->alerts($organization, $project),
            'history' => $this->history($project),
            'metricOptions' => AlertMetric::options(),
            'directionOptions' => AlertDirection::options(),
            'comparisonOptions' => AlertComparison::options(),
            'statusOptions' => AlertStatus::options(),
            'environmentOptions' => $project->environments()->visible()->orderBy('name')->pluck('name')->all(),
            'limits' => [
                'maxPerProject' => MetricAlert::MAX_PER_PROJECT,
                'minWindowMinutes' => MetricAlert::MIN_WINDOW_MINUTES,
                'maxWindowMinutes' => MetricAlert::MAX_WINDOW_MINUTES,
                'previewWindows' => MetricAlertPreview::WINDOWS,
            ],
            'canManage' => Gate::allows('manageAlerts', $project),
        ]);
    }

    public function store(MetricAlertRequest $request, Organization $organization, Project $project): RedirectResponse
    {
        Gate::authorize('manageAlerts', $project);

        if ($project->metricAlerts()->count() >= MetricAlert::MAX_PER_PROJECT) {
            return back()->withErrors([
                'name' => __('alerts.validation.too_many', ['max' => MetricAlert::MAX_PER_PROJECT]),
            ]);
        }

        $alert = new MetricAlert($request->values());

        $project->metricAlerts()->save($alert);

        return back()->with('status', __('alerts.flash.created', ['name' => $alert->name]));
    }

    /**
     * Ändern.
     *
     * **Der Zustand wird dabei zurückgesetzt.** Eine geänderte Schwelle
     * beschreibt eine andere Lage; „kritisch seit gestern" wäre danach eine
     * Aussage über eine Regel, die es nicht mehr gibt. Der nächste Durchlauf
     * stellt binnen einer Minute fest, wie es mit den neuen Schwellen aussieht —
     * und meldet dann von neuem, falls nötig.
     */
    public function update(
        MetricAlertRequest $request,
        Organization $organization,
        Project $project,
        MetricAlert $metric_alert,
    ): RedirectResponse {
        Gate::authorize('manageAlerts', $project);

        $metric_alert->fill($request->values());
        $metric_alert->status = AlertStatus::Ok;
        $metric_alert->status_since = null;
        $metric_alert->last_value = null;
        $metric_alert->last_baseline = null;
        $metric_alert->last_evaluated_at = null;
        $metric_alert->save();

        return back()->with('status', __('alerts.flash.updated', ['name' => $metric_alert->name]));
    }

    /**
     * An- und abschalten.
     *
     * Ein abgeschalteter Alarm bleibt samt Schwellen stehen, wird aber nicht
     * mehr ausgewertet — der Weg, eine Überwachung während einer geplanten
     * Wartung ruhigzustellen, ohne sie neu einrichten zu müssen. Der Zustand
     * fällt dabei auf „in Ordnung" zurück: was während der Abschaltung geschah,
     * hat niemand beobachtet, und ein „kritisch" von vorgestern wieder
     * aufzunehmen wäre eine Meldung über eine Lage, die längst vorbei sein kann.
     */
    public function toggle(
        Organization $organization,
        Project $project,
        MetricAlert $metric_alert,
    ): RedirectResponse {
        Gate::authorize('manageAlerts', $project);

        $metric_alert->is_active = ! $metric_alert->is_active;
        $metric_alert->status = AlertStatus::Ok;
        $metric_alert->status_since = null;
        $metric_alert->save();

        return back()->with('status', __(
            $metric_alert->is_active ? 'alerts.flash.enabled' : 'alerts.flash.disabled',
            ['name' => $metric_alert->name],
        ));
    }

    public function destroy(
        Organization $organization,
        Project $project,
        MetricAlert $metric_alert,
    ): RedirectResponse {
        Gate::authorize('manageAlerts', $project);

        $name = $metric_alert->name;

        $metric_alert->delete();

        return back()->with('status', __('alerts.flash.deleted', ['name' => $name]));
    }

    /**
     * Die Vorschau: der Verlauf der Kennzahl mit den Schwellen darüber.
     *
     * Ein `POST`, obwohl nichts geändert wird — die Vorschau bezieht sich auf
     * eine Einstellung, die noch **nicht** gespeichert ist. Genau darin liegt
     * ihr Zweck: nachsehen, bevor man sich festlegt. Der Alarm wird deshalb aus
     * den übermittelten Werten gebaut und nicht aus der Datenbank gelesen.
     */
    public function preview(
        MetricAlertRequest $request,
        Organization $organization,
        Project $project,
        MetricAlertPreview $preview,
    ): JsonResponse {
        Gate::authorize('view', $project);

        $alert = new MetricAlert($request->values());
        $alert->project_id = $project->id;
        $alert->setRelation('project', $project);

        return response()->json($preview->build($alert));
    }

    /**
     * Die Alarme für die Anzeige.
     *
     * @return list<array<string, mixed>>
     */
    private function alerts(Organization $organization, Project $project): array
    {
        return $project->metricAlerts()
            ->orderBy('name')
            ->get()
            ->map(fn (MetricAlert $alert): array => [
                'id' => $alert->id,
                'name' => $alert->name,
                'metric' => $alert->metric->value,
                'metricLabel' => $alert->metric->label(),
                'direction' => $alert->direction->value,
                'directionLabel' => $alert->direction->label(),
                'comparison' => $alert->comparison->value,
                'comparisonLabel' => $alert->comparison->label(),
                'environment' => $alert->environment,
                'transactionName' => $alert->transaction_name,
                'windowMinutes' => $alert->window_minutes,
                'warningThreshold' => $alert->warning_threshold,
                'criticalThreshold' => $alert->critical_threshold,
                'resolveThreshold' => $alert->resolve_threshold,
                'minimumSamples' => $alert->minimum_samples,
                'unit' => $alert->unit(),
                'active' => $alert->is_active,
                'status' => $alert->status->value,
                'statusLabel' => $alert->status->label(),
                'statusSince' => $alert->status_since?->toIso8601String(),
                'statusSinceLabel' => Formats::dateTime($alert->status_since),
                // Was zuletzt gerechnet wurde — der Lebensbeweis der Regel. Eine
                // Regel ohne diesen Zeitpunkt wurde noch nie ausgewertet; das ist
                // etwas anderes als „alles in Ordnung".
                'lastValue' => $alert->last_value,
                'lastValueLabel' => $alert->last_value === null
                    ? null
                    : Formats::number($alert->last_value, $alert->metric->decimals()),
                'lastEvaluatedLabel' => Formats::dateTime($alert->last_evaluated_at),
                'href' => route('projects.alerts.update', [$organization, $project, $alert]),
                'toggleHref' => route('projects.alerts.toggle', [$organization, $project, $alert]),
            ])
            ->values()
            ->all();
    }

    /**
     * Der Verlauf über alle Alarme des Projekts, jüngster zuerst.
     *
     * Über alle und nicht je Alarm: die Frage, die jemand nach einer Störung
     * stellt, ist „was war heute Nacht los?" und nicht „was war mit Alarm Nr. 3?".
     *
     * @return list<array<string, mixed>>
     */
    private function history(Project $project): array
    {
        return MetricAlertTransition::query()
            ->whereIn('metric_alert_id', $project->metricAlerts()->select('id'))
            ->with('alert:id,name,metric,comparison')
            ->latestFirst()
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->map(fn (MetricAlertTransition $transition): array => [
                'id' => $transition->id,
                'alert' => $transition->alert?->name ?? '',
                'kind' => $transition->kind(),
                'kindLabel' => __('alerts.kind.'.$transition->kind()),
                'fromStatus' => $transition->from_status->value,
                'toStatus' => $transition->to_status->value,
                'toStatusLabel' => $transition->to_status->label(),
                'value' => $transition->value,
                'valueLabel' => Formats::number(
                    $transition->value,
                    $transition->alert?->metric->decimals() ?? 0,
                ),
                'occurredAt' => $transition->occurred_at->toIso8601String(),
                'occurredAtLabel' => Formats::dateTime($transition->occurred_at),
            ])
            ->values()
            ->all();
    }
}
