<?php

namespace App\Http\Controllers;

use App\Enums\IssueAlertAction;
use App\Enums\IssueAlertComparison;
use App\Enums\IssueAlertCondition;
use App\Enums\IssueAlertFilter;
use App\Enums\IssueAlertMatch;
use App\Http\Requests\IssueAlertRuleRequest;
use App\Models\IssueAlertRule;
use App\Models\IssueAlertTrigger;
use App\Models\NotificationChannel;
use App\Models\Organization;
use App\Models\Project;
use App\Support\Formats;
use App\Support\IssueAlerts\IssueAlertPreview;
use App\Support\IssueAlerts\RuleAction;
use App\Support\IssueAlerts\RuleCondition;
use App\Support\IssueAlerts\RuleFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Die Alarm-Regeln eines Projekts: anlegen, ändern, an- und abschalten,
 * löschen — und vorher nachsehen, welche Fehler eine Regel derzeit träfe.
 *
 * Dieselbe Rechteteilung wie bei den Schwellwert-Alarmen (A3): ansehen darf
 * jedes Mitglied, denn welche Regeln scharf sind, ist die erste Frage, wenn
 * etwas **nicht** gemeldet wurde. Ändern darf nur die Verwaltung — eine
 * abgeschaltete Regel ist eine Überwachung, die jemand leiser gedreht hat.
 */
class IssueAlertRuleController extends Controller
{
    /**
     * Wie viele Auslösungen der Verlauf zeigt.
     */
    private const HISTORY_LIMIT = 25;

    public function index(Organization $organization, Project $project): InertiaResponse
    {
        Gate::authorize('view', $project);

        return Inertia::render('projects/IssueAlerts', [
            'organization' => [
                'name' => $organization->name,
                'href' => route('organizations.show', $organization),
            ],
            'project' => [
                'name' => $project->name,
                'href' => route('projects.show', [$organization, $project]),
                'rulesHref' => route('projects.issue-alerts.store', [$organization, $project]),
                'previewHref' => route('projects.issue-alerts.preview', [$organization, $project]),
            ],
            'rules' => $this->rules($organization, $project),
            'history' => $this->history($project),
            'conditionOptions' => IssueAlertCondition::options(),
            'filterOptions' => IssueAlertFilter::options(),
            'comparisonOptions' => IssueAlertComparison::options(),
            'actionOptions' => IssueAlertAction::options(),
            'matchOptions' => IssueAlertMatch::options(),
            'channelOptions' => $this->channels($organization),
            'limits' => [
                'maxPerProject' => IssueAlertRule::MAX_PER_PROJECT,
                'minFrequencyMinutes' => IssueAlertRule::MIN_FREQUENCY_MINUTES,
                'maxFrequencyMinutes' => IssueAlertRule::MAX_FREQUENCY_MINUTES,
                'previewLookbackDays' => IssueAlertPreview::LOOKBACK_DAYS,
            ],
            'canManage' => Gate::allows('manageAlerts', $project),
        ]);
    }

    public function store(IssueAlertRuleRequest $request, Organization $organization, Project $project): RedirectResponse
    {
        Gate::authorize('manageAlerts', $project);

        if ($project->issueAlertRules()->count() >= IssueAlertRule::MAX_PER_PROJECT) {
            return back()->withErrors([
                'name' => __('issue_alerts.validation.too_many', ['max' => IssueAlertRule::MAX_PER_PROJECT]),
            ]);
        }

        $rule = new IssueAlertRule($request->values());

        $project->issueAlertRules()->save($rule);

        return back()->with('status', __('issue_alerts.flash.created', ['name' => $rule->name]));
    }

    /**
     * Ändern.
     *
     * **Die Begrenzung wird dabei zurückgesetzt** (die Zustandszeilen fallen
     * weg). Eine geänderte Regel beschreibt einen anderen Anlass; „für diesen
     * Fehler schon gemeldet" wäre danach eine Aussage über eine Regel, die es
     * nicht mehr gibt — und der erste Fall, auf den die neue Fassung zutrifft,
     * bliebe still.
     */
    public function update(
        IssueAlertRuleRequest $request,
        Organization $organization,
        Project $project,
        IssueAlertRule $issue_alert_rule,
    ): RedirectResponse {
        Gate::authorize('manageAlerts', $project);

        $issue_alert_rule->fill($request->values());
        $issue_alert_rule->save();
        $issue_alert_rule->states()->delete();

        return back()->with('status', __('issue_alerts.flash.updated', ['name' => $issue_alert_rule->name]));
    }

    /**
     * An- und abschalten.
     *
     * Die Regel bleibt samt Bedingungen stehen, wird aber nicht mehr
     * ausgewertet — der Weg, eine Regel während einer geplanten Wartung
     * ruhigzustellen, ohne sie neu einrichten zu müssen. Die Begrenzung fällt
     * dabei weg: was während der Abschaltung geschah, hat niemand gemeldet, und
     * der erste Fall danach soll durchkommen.
     */
    public function toggle(
        Organization $organization,
        Project $project,
        IssueAlertRule $issue_alert_rule,
    ): RedirectResponse {
        Gate::authorize('manageAlerts', $project);

        $issue_alert_rule->is_active = ! $issue_alert_rule->is_active;
        $issue_alert_rule->save();
        $issue_alert_rule->states()->delete();

        return back()->with('status', __(
            $issue_alert_rule->is_active ? 'issue_alerts.flash.enabled' : 'issue_alerts.flash.disabled',
            ['name' => $issue_alert_rule->name],
        ));
    }

    public function destroy(
        Organization $organization,
        Project $project,
        IssueAlertRule $issue_alert_rule,
    ): RedirectResponse {
        Gate::authorize('manageAlerts', $project);

        $name = $issue_alert_rule->name;

        $issue_alert_rule->delete();

        return back()->with('status', __('issue_alerts.flash.deleted', ['name' => $name]));
    }

    /**
     * Die Vorschau: welche Fehler die Regel derzeit träfe.
     *
     * Ein `POST`, obwohl nichts geändert wird — dieselbe Wahl wie bei den
     * Schwellwert-Alarmen: die Vorschau bezieht sich auf einen Entwurf, der
     * noch **nicht** gespeichert ist, und trägt ihn deshalb im Rumpf.
     */
    public function preview(
        IssueAlertRuleRequest $request,
        Organization $organization,
        Project $project,
        IssueAlertPreview $preview,
    ): JsonResponse {
        Gate::authorize('view', $project);

        return response()->json($preview->build($request->draft(), $project));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rules(Organization $organization, Project $project): array
    {
        return $project->issueAlertRules()
            ->withCount('triggers')
            ->orderBy('name')
            ->get()
            ->map(fn (IssueAlertRule $rule): array => [
                'id' => $rule->id,
                'name' => $rule->name,
                'conditionMatch' => $rule->condition_match->value,
                'filterMatch' => $rule->filter_match->value,
                'conditions' => array_map(
                    static fn (RuleCondition $condition): array => $condition->toArray(),
                    $rule->parsedConditions(),
                ),
                'filters' => array_map(
                    static fn (RuleFilter $filter): array => $filter->toArray(),
                    $rule->parsedFilters(),
                ),
                'actions' => array_map(
                    static fn (RuleAction $action): array => $action->toArray(),
                    $rule->parsedActions(),
                ),
                'frequencyMinutes' => $rule->frequency_minutes,
                'active' => $rule->is_active,
                'triggerCount' => $rule->triggers_count ?? 0,
                'href' => route('projects.issue-alerts.update', [$organization, $project, $rule]),
                'toggleHref' => route('projects.issue-alerts.toggle', [$organization, $project, $rule]),
            ])
            ->values()
            ->all();
    }

    /**
     * Der Verlauf über alle Regeln des Projekts, jüngste zuerst.
     *
     * Über alle und nicht je Regel — die Frage nach einer Störung ist „was war
     * heute Nacht los?" und nicht „was war mit Regel Nr. 3?".
     *
     * @return list<array<string, mixed>>
     */
    private function history(Project $project): array
    {
        return IssueAlertTrigger::query()
            ->whereIn('issue_alert_rule_id', $project->issueAlertRules()->select('id'))
            ->with(['rule:id,name', 'issue:id,title'])
            ->latestFirst()
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->map(fn (IssueAlertTrigger $trigger): array => [
                'id' => $trigger->id,
                'rule' => $trigger->rule?->name,
                'issue' => $trigger->issue?->title,
                'issueHref' => $trigger->issue === null ? null : route('issues.show', $trigger->issue),
                'reasons' => array_map(
                    static fn (IssueAlertCondition $condition): string => $condition->label(),
                    $trigger->conditionTypes(),
                ),
                // Wie viele Zustellungen daraus entstanden sind. Eine Null ist
                // die aussagekräftigste Zahl der Liste: die Regel hat gegriffen
                // und es ist nichts hinausgegangen — kein aktiver Kanal.
                'deliveryCount' => $trigger->delivery_count,
                'occurredAt' => $trigger->occurred_at->toIso8601String(),
                'occurredAtLabel' => Formats::dateTime($trigger->occurred_at),
            ])
            ->values()
            ->all();
    }

    /**
     * Die Kanäle, unter denen eine Aktion wählen kann.
     *
     * @return list<array{value: int, label: string, active: bool}>
     */
    private function channels(Organization $organization): array
    {
        return $organization->notificationChannels()
            ->orderBy('name')
            ->get()
            ->map(fn (NotificationChannel $channel): array => [
                'value' => $channel->id,
                'label' => $channel->name,
                'active' => $channel->is_active,
            ])
            ->values()
            ->all();
    }
}
