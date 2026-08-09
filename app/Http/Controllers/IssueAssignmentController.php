<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Issue;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Support\CurrentOrganization;
use App\Support\Issues\EventNavigation;
use App\Support\Issues\IssueAssignee;
use App\Support\Ownership\OwnershipAssignment;
use App\Support\Ownership\OwnershipSubjects;
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
 * **Zwei Herleitungen führen die Liste an, und beide nur, wenn der Aufruf sagt,
 * um welchen Fehler es geht** (`?issue=`). Ohne die Angabe bleibt es bei „wer
 * kommt überhaupt in Frage": eine Sammelaktion über zwölf Einträge hat keinen
 * Stacktrace, gegen den sich etwas abgleichen ließe.
 *
 *   1. **Die Zuständigkeits-Regeln** (R6) — sie stehen ganz oben, weil sie das
 *      einzige sind, was jemand ausdrücklich **entschieden** hat: „Fehler aus
 *      `src/billing/*` gehören der Kasse".
 *   2. **Die Autoren der verdächtigen Commits** (R4) — ein Abgleich, der sich
 *      irren kann, und deshalb hinter der Entscheidung.
 *
 * Beide bringen ihre Begründung als `hint` mit; ein Vorschlag, der oben steht
 * und nicht sagt, warum, sieht aus wie eine willkürliche Sortierung. Für den
 * Aufrufer ist das kein Unterschied — er bekommt eine geordnete Liste, und woher
 * ihre Reihenfolge stammt, ist die Sache dieser Klasse.
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

        // Die Organisation aus der Adresse und **nicht** die Projekte der
        // Filterleiste: zuständig wird man in einer Organisation, und eine
        // Auswahlliste, die sich mit der Projektauswahl ändert, wäre bei einer
        // Sammelaktion über mehrere Projekte nicht mehr zu beantworten.
        $organization = CurrentOrganization::for($request);

        if (! $organization instanceof Organization) {
            return response()->json(['suggestions' => []]);
        }

        $term = trim((string) $request->query('q', ''));
        $issue = $this->issue($request, $organization);

        // Die beiden Herleitungen, in ihrer Rangfolge. Wer schon hier steht,
        // erscheint weiter unten nicht noch einmal — derselbe Name zweimal sieht
        // nach einem Fehler aus.
        $ownership = $this->ownership($issue, $term);
        $suspects = $this->suspects($issue, $term, array_column($ownership, 'value'));

        $leading = [...$ownership, ...$suspects];

        // Zwei Kennungen für zwei Fragen: `value` (der Text) schließt Teams mit
        // ein und dient dem Ausblenden in der Team- und Mitgliederliste; `id`
        // gibt es nur bei Konten und ist das, wonach die Abfrage der Mitglieder
        // filtern kann. Die Kennung selbst geht nicht mit hinaus — sie ist eine
        // Hilfsgröße dieser Klasse und kein Teil der Auskunft.
        $seen = array_values(array_filter(array_column($leading, 'id')));
        $taken = array_column($leading, 'value');

        return response()->json([
            'suggestions' => [
                ...array_map(
                    static fn (array $suggestion): array => Arr::except($suggestion, ['id']),
                    $leading,
                ),
                // Der Betrachter selbst danach und mit dem festen Text `me`:
                // „mir zuweisen" ist der häufigste Fall, und `me` bleibt
                // richtig, auch wenn sich die eigene Adresse ändert.
                ...$this->self($viewer, $term, $seen),
                // Dann die Teams: sie sind die wenigeren und die, die man
                // seltener ausgeschrieben im Kopf hat.
                ...$this->without($this->teams($organization, $term), $taken),
                ...$this->members($organization, $viewer, $term, $seen),
            ],
        ]);
    }

    /**
     * Der Fehler, um den es geht — oder `null`.
     *
     * **Ohne `?issue=` gibt es keinen**, und das ist der Regelfall bei einer
     * Sammelaktion. Ein Fehler, den der Betrachter nicht sehen darf oder der
     * einer anderen Organisation gehört, wird still übergangen: die Liste ist
     * eine Auswahlhilfe und nicht der Ort, an dem sich die Sichtbarkeit von
     * Einträgen erfragen lässt.
     */
    private function issue(Request $request, Organization $organization): ?Issue
    {
        $id = $request->query('issue');

        if (! is_string($id) || ! ctype_digit($id)) {
            return null;
        }

        $issue = Issue::query()->with('project.organization')->find((int) $id);

        if ($issue === null || $issue->project?->organization_id !== $organization->id) {
            return null;
        }

        return Gate::denies('view', $issue) ? null : $issue;
    }

    /**
     * Was die Zuständigkeits-Regeln zu diesem Fehler sagen (R6).
     *
     * Ausgewertet wird gegen das **zuletzt** gesehene Ereignis: der Eintrag
     * selbst trägt weder Pfade noch Merkmale, und der Ort eines Fehlers kann
     * sich über die Zeit verschieben — im ältesten Ereignis steht nach einem
     * Umbau ein Pfad, den es nicht mehr gibt. Dieselbe Wahl wie bei den
     * verdächtigen Commits, und dieselbe Quelle ({@see EventNavigation}).
     *
     * @return list<array{id: int|null, value: string, label: string, kind: string, hint: string}>
     */
    private function ownership(?Issue $issue, string $term): array
    {
        $project = $issue?->project;
        $event = $issue === null ? null : EventNavigation::newest($issue);

        if (! $project instanceof Project || ! $event instanceof Event) {
            return [];
        }

        $suggestions = [];

        foreach (app(OwnershipAssignment::class)->suggest(OwnershipSubjects::fromEvent($event), $project) as $match) {
            $assignee = $match['assignee'];
            $rule = $match['rule'];

            if (! $this->matches($assignee->label(), $term)) {
                continue;
            }

            $suggestions[] = [
                // Nur bei einer Person: ein Team hat keine Kontokennung, und die
                // Mitgliederliste darunter filtert nach genau der.
                'id' => $assignee->user?->id,
                'value' => $assignee->term(),
                'label' => $assignee->label(),
                'kind' => 'ownership',
                'hint' => __('ownership.suggestion', ['pattern' => $rule->expression()]),
            ];
        }

        return array_slice($suggestions, 0, self::LIMIT);
    }

    /**
     * Die Autoren der verdächtigen Commits (R4).
     *
     * Vorgeschlagen werden nur Autoren **mit Konto**: eine Adresse aus einem
     * Repository ist keine Zuständigkeit, die sich vergeben ließe. Die
     * Begründung reist als `hint` mit — ein Vorschlag, der weit oben steht und
     * nicht sagt, warum, sieht aus wie eine willkürliche Sortierung.
     *
     * @param  list<string>  $taken  Zuständige, die als Regeltreffer schon oben stehen
     * @return list<array{id: int|null, value: string, label: string, kind: string, hint: string}>
     */
    private function suspects(?Issue $issue, string $term, array $taken = []): array
    {
        if ($issue === null) {
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

            $entry = [
                'id' => $author->id,
                'value' => IssueAssignee::forUser($author)->term(),
                'label' => $author->name,
                'kind' => 'suspect',
                'hint' => __('issues.suspects.suggestion', ['sha' => $suspect->commit->shortSha()]),
            ];

            if (in_array($entry['value'], $taken, true)) {
                continue;
            }

            $suggestions[] = $entry;
        }

        return $suggestions;
    }

    /**
     * @param  list<int>  $seen  Konten, die als Vorschlag schon oben stehen
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
     * @param  list<int>  $seen  Konten, die als Vorschlag schon oben stehen
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
     * Dieselbe Liste ohne die Zuständigen, die schon oben stehen.
     *
     * Für die Teams, die als Einzige nicht über eine Kontokennung ausgeschlossen
     * werden können — ein Team hat keine.
     *
     * @param  list<array{value: string, label: string, kind: string}>  $suggestions
     * @param  list<string>  $taken
     * @return list<array{value: string, label: string, kind: string}>
     */
    private function without(array $suggestions, array $taken): array
    {
        return array_values(array_filter(
            $suggestions,
            static fn (array $suggestion): bool => ! in_array($suggestion['value'], $taken, true),
        ));
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
