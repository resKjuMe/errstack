<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Support\Issues\IssueAssignee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
 * **Automatische Vorschläge kommen später.** Die Zuständigkeits-Regeln (R6) und
 * die verdächtigen Commits (R4) werden die Liste anführen können, sobald es sie
 * gibt; bis dahin steht hier, wer überhaupt in Frage kommt. Der Unterschied ist
 * für den Aufrufer keiner — er bekommt eine geordnete Liste, und woher ihre
 * Reihenfolge stammt, ist die Sache dieser Klasse.
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

        return response()->json([
            'suggestions' => [
                // Der Betrachter selbst zuerst und mit dem festen Text `me`:
                // „mir zuweisen" ist der häufigste Fall, und `me` bleibt
                // richtig, auch wenn sich die eigene Adresse ändert.
                ...$this->self($viewer, $term),
                // Dann die Teams: sie sind die wenigeren und die, die man
                // seltener ausgeschrieben im Kopf hat.
                ...$this->teams($organization, $term),
                ...$this->members($organization, $viewer, $term),
            ],
        ]);
    }

    /**
     * @return list<array{value: string, label: string, kind: string}>
     */
    private function self(?User $viewer, string $term): array
    {
        if ($viewer === null || ! $this->matches($viewer->name, $term)) {
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
     * @return list<array{value: string, label: string, kind: string}>
     */
    private function members(Organization $organization, ?User $viewer, string $term): array
    {
        return User::query()
            ->select(['users.id', 'users.name', 'users.email'])
            ->join('organization_user', 'organization_user.user_id', '=', 'users.id')
            ->where('organization_user.organization_id', $organization->id)
            ->when($viewer !== null, fn ($query) => $query->where('users.id', '!=', $viewer->id))
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
