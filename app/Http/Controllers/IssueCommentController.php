<?php

namespace App\Http\Controllers;

use App\Enums\IssueCategory;
use App\Http\Requests\IssueCommentRequest;
use App\Models\Issue;
use App\Models\IssueComment;
use App\Models\Team;
use App\Models\User;
use App\Support\Issues\IssueComments;
use App\Support\Issues\Mentions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Die Kommentare an einem Fehler: schreiben, ändern, zurücknehmen — und die
 * Vorschläge fürs `@`.
 *
 * Geschrieben wird nichts hier, sondern in {@see IssueComments}: an einem
 * Kommentar hängen die Nennungen und an ihnen die Benachrichtigungen, und das
 * gehört nicht in einen Controller, den morgen ein zweiter Einstiegspunkt (die
 * öffentliche Schnittstelle, X5) umgeht.
 */
class IssueCommentController extends Controller
{
    /**
     * Wie viele Vorschläge das Eingabefeld anbietet.
     *
     * Genug, um zu wählen, und wenig genug, um nicht zu blättern: wer nach vier
     * getippten Zeichen mehr als zehn Treffer hat, tippt weiter, statt zu
     * suchen.
     */
    private const SUGGESTION_LIMIT = 10;

    public function store(IssueCommentRequest $request, Issue $issue): RedirectResponse
    {
        Gate::authorize('create', [IssueComment::class, $issue]);

        $this->errorsOnly($issue);

        app(IssueComments::class)->create($issue, $request->user(), $request->body());

        return back()->with('status', __('issues.comments.flash.created'));
    }

    public function update(IssueCommentRequest $request, Issue $issue, IssueComment $comment): RedirectResponse
    {
        $this->belongsTo($issue, $comment);

        Gate::authorize('update', $comment);

        app(IssueComments::class)->update($comment, $request->body());

        return back()->with('status', __('issues.comments.flash.updated'));
    }

    public function destroy(Issue $issue, IssueComment $comment): RedirectResponse
    {
        $this->belongsTo($issue, $comment);

        Gate::authorize('delete', $comment);

        app(IssueComments::class)->delete($comment);

        return back()->with('status', __('issues.comments.flash.deleted'));
    }

    /**
     * Wen man hier nennen kann — die Vorschläge hinter dem `@`.
     *
     * Sie kommen vom Server und stehen nicht in der Seite: die Mitgliederliste
     * einer Organisation in jede Fehlerseite zu schreiben, wäre bei zweihundert
     * Konten ein Vielfaches der Seite selbst — für ein Feld, das die meisten
     * Aufrufe nie anfassen. Dieselbe Überlegung wie bei den Suchvorschlägen
     * ({@see IssueSearchController}).
     *
     * Vorgeschlagen wird nur, wer den Fehler auch sehen darf: Mitglieder und
     * Teams **dieser** Organisation. Eine Nennung außerhalb wäre eine Auskunft
     * über fremde Projekte.
     */
    public function suggest(Request $request, Issue $issue): JsonResponse
    {
        Gate::authorize('view', $issue);

        $organization = $issue->project?->organization;

        if ($organization === null) {
            return response()->json(['suggestions' => []]);
        }

        $term = trim((string) $request->query('q', ''));

        $users = User::query()
            ->select(['users.id', 'users.name'])
            ->join('organization_user', 'organization_user.user_id', '=', 'users.id')
            ->where('organization_user.organization_id', $organization->id)
            ->when($term !== '', fn ($query) => $query->where('users.name', 'like', '%'.$term.'%'))
            ->orderBy('users.name')
            ->limit(self::SUGGESTION_LIMIT)
            ->get()
            ->map(static fn (User $user): array => [
                'kind' => 'user',
                'name' => $user->name,
            ]);

        $teams = Team::query()
            ->where('organization_id', $organization->id)
            ->when($term !== '', fn ($query) => $query->where('name', 'like', '%'.$term.'%'))
            ->orderBy('name')
            ->limit(self::SUGGESTION_LIMIT)
            ->get(['id', 'name'])
            ->map(static fn (Team $team): array => [
                'kind' => 'team',
                'name' => $team->name,
            ]);

        return response()->json([
            // Teams zuerst: sie sind die wenigeren und die, die man seltener
            // ausgeschrieben im Kopf hat.
            'suggestions' => $teams->concat($users)->take(self::SUGGESTION_LIMIT)->values()->all(),
            'limit' => Mentions::LIMIT,
        ]);
    }

    /**
     * Der Kommentar muss zu diesem Fehler gehören.
     *
     * Beide Kennungen stehen in der Adresszeile, und eine vertauschte Zeile
     * darf keinen fremden Kommentar unter fremdem Fehler ändern — dieselbe
     * Prüfung, die die Detailseite für Meldungen macht.
     */
    private function belongsTo(Issue $issue, IssueComment $comment): void
    {
        if ($comment->issue_id !== $issue->id) {
            throw new NotFoundHttpException;
        }

        $this->errorsOnly($issue);
    }

    /**
     * Kommentiert wird an Fehlern.
     *
     * Seit PF6 stehen zwei Arten von Einträgen in derselben Tabelle, und die
     * Kennung in der Adresszeile unterscheidet sie nicht — dieselbe Sperre wie
     * auf der Detailseite ({@see IssueDetailController}).
     */
    private function errorsOnly(Issue $issue): void
    {
        if ($issue->category !== IssueCategory::Error) {
            throw new NotFoundHttpException;
        }
    }
}
