<?php

namespace App\Http\Controllers;

use App\Models\Issue;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Support\Issues\EventNavigation;
use App\Support\Issues\IssueAssignee;
use App\Support\Releases\SuspectCommits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;

/**
 * Wem sich ein Fehler zuweisen lässt — die Vorschlagsliste der Aktionsleiste.
 *
 * Sie kommt vom Server und steht nicht in der Seite: die Mitgliederliste einer
 * Organisation in **jede** Fehlerliste zu schreiben wäre bei zweihundert Konten
 * ein Vielfaches der Seite selbst — für ein Feld, das die meisten Aufrufe nie
 * anfassen. Dieselbe Überlegung wie bei den Suchvorschlägen
 * ({@see IssueSearchController}) und bei den Vorschlägen fürs `@`
 * ({@see IssueCommentController}).
 *
 * **Vorgeschlagen wird ein Text, keine Kennung.** Was hier zurückkommt, ist
 * genau das, was jemand auch ins Suchfeld tippen könnte
 * ({@see IssueAssignee::term()}) — dieselbe Schreibweise für die Auswahlliste,
 * das Formular und die Suche. Ein Formular, das eine Kennung schickt, wäre der
 * zweite Weg, denselben Zuständigen zu benennen, und der erste, der bei einer
 * Änderung übersehen wird.
 *
 * **Die Autoren der verdächtigen Commits führen die Liste an** (R4) — aber nur,
 * wenn der Aufruf sagt, um welchen Fehler es geht (`?issue=`). Ohne die Angabe
 * bleibt es bei „wer kommt überhaupt in Frage": eine Sammelaktion über zwölf
 * Einträge hat keinen Stacktrace, gegen den sich etwas abgleichen ließe. Der
 * Unterschied ist für den Aufrufer keiner — er bekommt eine geordnete Liste, und
 * woher ihre Reihenfolge stammt, ist die Sache dieser Klasse.
 *
 * **Die Zuständigkeits-Regeln (R6) kommen später** und werden sich an dieselbe
 * Stelle setzen.
 */
class IssueAssignmentController extends Controller
{
    /**
     * Wie viele Vorschläge die Auswahlliste anbietet.
     *
     * Genug, um zu wählen, und wenig genug, um nicht zu blättern — wie bei den
     * Nennungen. Wer in einer Organisation mit dreihundert Konten jemanden
     * sucht, tippt, statt zu scrollen.
     */
    private const LIMIT = 10;

    public function __invoke(Request $request): JsonResponse
    {
        $viewer = $request->user();

        // Die aktive Organisation des Betrachters und **nicht** die Projekte der
        // Filterleiste: zuständig wird man in einer Organisation, und eine
        // Auswahlliste, die sich mit der Projektauswahl ändert, wäre bei einer
        // Sammelaktion über mehrere Projekte nicht mehr zu beantworten.
        $organization = $viewer?->resolveCurrentOrganization();

        if (! $organization instanceof Organization) {
            return response()->json(['suggestions' => []]);
        }

        $term = trim((string) $request->query('q', ''));

        // Die Autoren der verdächtigen Commits (R4) — sie stehen ganz oben, weil
        // sie als Einzige eine Begründung mitbringen. Wer schon hier steht,
        // erscheint weiter unten nicht noch einmal.
        $suspects = $this->suspects($request, $term);
        $seen = array_column($suspects, 'id');

        return response()->json([
            'suggestions' => [
                ...array_map(
                    static fn (array $suggestion): array => Arr::except($suggestion, ['id']),
                    $suspects,
                ),
                // Der Betrachter selbst danach und mit dem festen Text `me`:
                // „mir zuweisen" ist der häufigste Fall, und `me` bleibt
                // richtig, auch wenn sich die eigene Adresse ändert.
                ...$this->self($viewer, $term, $seen),
                // Dann die Teams: sie sind die wenigeren und die, die man
                // seltener ausgeschrieben im Kopf hat.
                ...$this->teams($organization, $term),
                ...$this->members($organization, $viewer, $term, $seen),
            ],
        ]);
    }

    /**
     * Die Autoren der verdächtigen Commits — sofern der Aufruf einen Fehler
     * nennt und der Betrachter ihn sehen darf.
     *
     * Vorgeschlagen werden nur Autoren **mit Konto**: eine Adresse aus einem
     * Repository ist keine Zuständigkeit, die sich vergeben ließe. Die
     * Begründung reist als `hint` mit — ein Vorschlag, der ganz oben steht und
     * nicht sagt, warum, sieht aus wie eine willkürliche Sortierung.
     *
     * @return list<array{id: int, value: string, label: string, kind: string, hint: string}>
     */
    private function suspects(Request $request, string $term): array
    {
        $id = $request->query('issue');

        if (! is_string($id) || ! ctype_digit($id)) {
            return [];
        }

        $issue = Issue::query()->find((int) $id);

        if ($issue === null || Gate::denies('view', $issue)) {
            return [];
        }

        $suggestions = [];

        foreach (SuspectCommits::forEvent($issue, EventNavigation::newest($issue)) as $suspect) {
            $author = $suspect->authorId() === null ? null : $suspect->commit->author;

            if ($author === null || ! $this->matches($author->name, $term)) {
                continue;
            }

            if (in_array($author->id, array_column($suggestions, 'id'), strict: true)) {
                continue;
            }

            $suggestions[] = [
                'id' => $author->id,
                'value' => IssueAssignee::forUser($author)->term(),
                'label' => $author->name,
                'kind' => 'suspect',
                'hint' => __('issues.suspects.suggestion', ['sha' => $suspect->commit->shortSha()]),
            ];
        }

        return $suggestions;
    }

    /**
     * @param  list<int>  $seen  Konten, die als Verdächtige schon oben stehen
     * @return list<array{value: string, label: string, kind: string}>
     */
    private function self(?User $viewer, string $term, array $seen = []): array
    {
        if ($viewer === null || ! $this->matches($viewer->name, $term)) {
            return [];
        }

        if (in_array($viewer->id, $seen, strict: true)) {
            return [];
        }

        return [[
            'value' => IssueAssignee::SELF,
            'label' => $viewer->name,
            'kind' => 'self',
        ]];
    }

    /**
     * @return list<array{value: string, label: string, kind: string}>
     */
    private function teams(Organization $organization, string $term): array
    {
        return Team::query()
            ->where('organization_id', $organization->id)
            ->when($term !== '', fn ($query) => $query->where('name', 'like', '%'.$term.'%'))
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get(['id', 'name'])
            ->map(static fn (Team $team): array => [
                'value' => IssueAssignee::forTeam($team)->term(),
                'label' => $team->name,
                'kind' => 'team',
            ])
            ->all();
    }

    /**
     * Die Mitglieder der Organisation — der Betrachter selbst nicht noch einmal,
     * er steht schon oben.
     *
     * @param  list<int>  $seen  Konten, die als Verdächtige schon oben stehen
     * @return list<array{value: string, label: string, kind: string}>
     */
    private function members(Organization $organization, ?User $viewer, string $term, array $seen = []): array
    {
        return User::query()
            ->select(['users.id', 'users.name', 'users.email'])
            ->join('organization_user', 'organization_user.user_id', '=', 'users.id')
            ->where('organization_user.organization_id', $organization->id)
            ->when($viewer !== null, fn ($query) => $query->where('users.id', '!=', $viewer->id))
            ->when($seen !== [], fn ($query) => $query->whereNotIn('users.id', $seen))
            ->when($term !== '', fn ($query) => $query->where(
                fn ($any) => $any
                    ->where('users.name', 'like', '%'.$term.'%')
                    ->orWhere('users.email', 'like', '%'.$term.'%'),
            ))
            ->orderBy('users.name')
            ->limit(self::LIMIT)
            ->get()
            ->map(static fn (User $user): array => [
                'value' => IssueAssignee::forUser($user)->term(),
                'label' => $user->name,
                'kind' => 'user',
            ])
            ->all();
    }

    /**
     * Der Vergleich für den Betrachter selbst — im Speicher, weil es genau einen
     * gibt und eine Abfrage dafür Verschwendung wäre.
     */
    private function matches(string $name, string $term): bool
    {
        return $term === '' || str_contains(mb_strtolower($name), mb_strtolower($term));
    }
}
