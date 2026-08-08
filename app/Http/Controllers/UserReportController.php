<?php

namespace App\Http\Controllers;

use App\Enums\UserReportStatus;
use App\Http\Requests\UserReportListRequest;
use App\Models\User;
use App\Models\UserReport;
use App\Support\Feedback\UserReportList;
use App\Support\Feedback\UserReportNotifier;
use App\Support\FilterData;
use App\Support\Formats;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Die Rückmeldungen betroffener Personen — die einzige Ansicht dieser Anwendung,
 * in der jemand mit eigenen Worten schreibt.
 *
 * Sie hängt wie die Fehlerliste nicht an einem Projekt in der Adresszeile:
 * welche Projekte gemeint sind, sagt die globale Filterleiste (F7).
 *
 * Zuweisung und Bearbeitungsstand sind zwei Wege und nicht einer mit zwei
 * Feldern. Sie beantworten verschiedene Fragen — „wer kümmert sich?" und „ist es
 * erledigt?" —, werden einzeln geändert, und ein gemeinsamer Weg müsste bei
 * jedem Aufruf entscheiden, welches der beiden Felder gemeint war.
 */
class UserReportController extends Controller
{
    public function __construct(private readonly UserReportNotifier $notifier) {}

    public function index(UserReportListRequest $request): InertiaResponse
    {
        $filter = $request->filter();
        $viewer = $request->user();

        $reports = UserReportList::paginate($filter, $viewer, $request->status(), $request->assignee());

        return Inertia::render('feedback/Index', [
            'filter' => FilterData::bar($filter),
            'reports' => $reports,
            'list' => $request->listValues(),
            'totalLabel' => Formats::number($reports->total()),
            // Zwei Listen und nicht eine mit einem herausgefilterten Eintrag:
            // „alle Stände" ist ein Filterwert und kein Stand. An einer
            // einzelnen Rückmeldung wäre er nicht auswählbar, sondern sinnlos.
            'filterStatusOptions' => [
                ['value' => UserReportListRequest::STATUS_ANY, 'label' => __('feedback.filter.any_status')],
                ...UserReportStatus::options(),
            ],
            'statusOptions' => UserReportStatus::options(),
            'assigneeOptions' => [
                ['value' => '', 'label' => __('feedback.filter.any_assignee')],
                ['value' => UserReportListRequest::ASSIGNEE_ME, 'label' => __('feedback.filter.assigned_to_me')],
                ['value' => UserReportListRequest::ASSIGNEE_NOBODY, 'label' => __('feedback.filter.unassigned')],
            ],
            'assignableUsers' => UserReportList::assignableUsers($filter),
            // Die Umgebung wirkt nicht: eine Rückmeldung schreibt ein Mensch,
            // keine Umgebung. Statt die Auswahl still zu übergehen, sagt die
            // Seite es — wie die Fehler- und die Versionsliste.
            'environmentIgnored' => $filter->environment !== null,
        ]);
    }

    /**
     * Den Bearbeitungsstand ändern.
     */
    public function status(Request $request, UserReport $userReport): RedirectResponse
    {
        Gate::authorize('update', $userReport);

        $validated = $request->validate([
            'status' => ['required', Rule::enum(UserReportStatus::class)],
        ]);

        $userReport->status = UserReportStatus::from((string) $validated['status']);
        $userReport->save();

        return back()->with('status', __('feedback.flash.status_changed', [
            'status' => $userReport->status->label(),
        ]));
    }

    /**
     * Die Rückmeldung jemandem übergeben — oder sie wieder freigeben.
     *
     * Benachrichtigt wird nur, wenn sich der Empfänger ändert und es nicht der
     * Absender selbst ist: „das liegt jetzt bei dir" an jemanden, der es gerade
     * selbst eingetragen hat, ist eine Nachricht ohne Inhalt.
     */
    public function assignment(Request $request, UserReport $userReport): RedirectResponse
    {
        Gate::authorize('update', $userReport);

        $validated = $request->validate([
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $previous = $userReport->assigned_to;
        $assignee = $validated['assigned_to'] === null
            ? null
            : User::query()->find($validated['assigned_to']);

        if ($assignee !== null && ! Gate::forUser($assignee)->allows('view', $userReport)) {
            // Zugewiesen wird nur an Mitglieder der Organisation. Sonst hinge
            // eine Zuschrift bei jemandem, der sie nicht einmal öffnen kann.
            return back()->withErrors([
                'assigned_to' => __('feedback.errors.not_a_member'),
            ]);
        }

        $userReport->assignTo($assignee);

        if ($assignee !== null && $assignee->id !== $previous && $assignee->id !== $request->user()->id) {
            $this->notifier->sendAssignment($userReport, $assignee);
        }

        return back()->with('status', $assignee === null
            ? __('feedback.flash.unassigned')
            : __('feedback.flash.assigned', ['name' => $assignee->name]));
    }
}
