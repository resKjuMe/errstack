<?php

namespace App\Http\Controllers;

use App\Enums\AlertSnoozeScope;
use App\Http\Requests\AlertSnoozeRequest;
use App\Models\AlertSnooze;
use App\Models\IssueAlertRule;
use App\Models\MetricAlert;
use App\Models\Organization;
use App\Models\Project;
use App\Support\Formats;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Eine Regel befristet stummschalten — und die Stummschaltung wieder aufheben.
 *
 * **Zwei Rechte, nicht eines.** Für alle darf nur, wer die Alarme auch
 * einrichten darf: das nimmt eine Überwachung für die ganze Organisation vom
 * Netz, und wer nachts Dienst hat, soll sich darauf verlassen können. Für sich
 * selbst darf jedes Mitglied — das ist keine Entscheidung über die Überwachung,
 * sondern über den eigenen Posteingang, und sie muss ohne Rückfrage möglich
 * sein, sonst hilft sie um drei Uhr nachts niemandem.
 *
 * **Was sie nicht tut:** die Auswertung anhalten. Zustandswechsel und
 * Auslösungen werden weiter festgestellt und stehen im Verlauf — genau darin
 * unterscheidet sie sich von dem Schalter an der Regel selbst
 * ({@see MetricAlertController::toggle()}). Wer sie dafür benutzt, eine kaputte
 * Regel loszuwerden, merkt beim Ablauf, dass sie noch da ist; das ist gewollt.
 */
class AlertSnoozeController extends Controller
{
    public function storeForMetricAlert(
        AlertSnoozeRequest $request,
        Organization $organization,
        Project $project,
        MetricAlert $metric_alert,
    ): RedirectResponse {
        return $this->store($request, $project, $metric_alert, $metric_alert->name);
    }

    public function destroyForMetricAlert(
        AlertSnoozeRequest $request,
        Organization $organization,
        Project $project,
        MetricAlert $metric_alert,
    ): RedirectResponse {
        return $this->destroy($request, $project, $metric_alert, $metric_alert->name);
    }

    public function storeForIssueAlertRule(
        AlertSnoozeRequest $request,
        Organization $organization,
        Project $project,
        IssueAlertRule $issue_alert_rule,
    ): RedirectResponse {
        return $this->store($request, $project, $issue_alert_rule, $issue_alert_rule->name);
    }

    public function destroyForIssueAlertRule(
        AlertSnoozeRequest $request,
        Organization $organization,
        Project $project,
        IssueAlertRule $issue_alert_rule,
    ): RedirectResponse {
        return $this->destroy($request, $project, $issue_alert_rule, $issue_alert_rule->name);
    }

    /**
     * Setzen — oder verlängern.
     *
     * `updateOrCreate` über den Geltungsbereich und nicht `create`: derselbe
     * Knopf ein zweites Mal gedrückt soll die Ruhe verlängern und keine zweite
     * Zeile daneben legen. Eine Verkürzung ist damit ebenfalls möglich, und das
     * ist richtig — „doch nur noch eine Stunde" ist eine ebenso sinnvolle
     * Angabe wie „doch lieber bis morgen".
     */
    private function store(
        AlertSnoozeRequest $request,
        Project $project,
        MetricAlert|IssueAlertRule $subject,
        string $name,
    ): RedirectResponse {
        $scope = $request->scope();

        $this->authorizeScope($scope, $project);

        $until = AlertSnooze::endOf($request->minutes());
        $viewer = $request->user()?->id;

        AlertSnooze::query()->updateOrCreate(
            $this->keys($subject, $scope, $viewer),
            [
                'until' => $until,
                'created_by_id' => $viewer,
            ],
        );

        return back()->with('status', __('alert_overview.flash.snoozed', [
            'name' => $name,
            'until' => (string) Formats::dateTime($until),
        ]));
    }

    private function destroy(
        AlertSnoozeRequest $request,
        Project $project,
        MetricAlert|IssueAlertRule $subject,
        string $name,
    ): RedirectResponse {
        $scope = $request->scope();

        $this->authorizeScope($scope, $project);

        // `delete` und nicht „auf jetzt setzen": eine abgelaufene Stummschaltung
        // ist keine Auskunft, die jemand später noch braucht — sie stünde nur
        // als Zeile herum, die nichts mehr bewirkt.
        AlertSnooze::query()->where($this->keys($subject, $scope, $request->user()?->id))->delete();

        return back()->with('status', __('alert_overview.flash.unsnoozed', ['name' => $name]));
    }

    /**
     * Für alle: dasselbe Recht wie zum Einrichten. Für sich selbst: das Recht,
     * das Projekt überhaupt zu sehen.
     */
    private function authorizeScope(AlertSnoozeScope $scope, Project $project): void
    {
        $scope === AlertSnoozeScope::Everyone
            ? Gate::authorize('manageAlerts', $project)
            : Gate::authorize('view', $project);
    }

    /**
     * Die Spalten, die einen Geltungsbereich eindeutig machen.
     *
     * Ein `null` bei `user_id` ist dabei kein „egal", sondern der Wert selbst:
     * die Abfrage sucht danach ausdrücklich (`is null`) und trifft damit genau
     * die Stummschaltung für alle — nicht irgendeine persönliche daneben.
     *
     * @return array<string, int|null>
     */
    private function keys(MetricAlert|IssueAlertRule $subject, AlertSnoozeScope $scope, ?int $viewer): array
    {
        $user = $scope === AlertSnoozeScope::Everyone ? null : $viewer;

        return $subject instanceof MetricAlert
            ? ['metric_alert_id' => $subject->id, 'issue_alert_rule_id' => null, 'user_id' => $user]
            : ['metric_alert_id' => null, 'issue_alert_rule_id' => $subject->id, 'user_id' => $user];
    }
}
