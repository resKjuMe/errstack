<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Issue;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Support\Issues\IssueAssignee;
use App\Support\Ownership\OwnershipAssignment;
use App\Support\Ownership\OwnershipSubjects;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
 * **Die Zuständigkeits-Regeln führen die Liste an** (R6), sobald der Aufruf
 * sagt, um welchen Fehler es geht (`?issue=`). Sie stehen oben und mit eigener
 * Art (`ownership`), damit die Oberfläche sie als Vorschlag ausweisen kann:
 * „das Regelwerk sagt die Kasse" ist eine andere Auskunft als „die Kasse gibt
 * es". Ohne Kennung — bei einer Sammelaktion über 12.480 Einträge — bleibt es
 * bei der reinen Auswahlliste: eine Zuständigkeit, die für den einen Fehler
 * gilt, gilt nicht für die anderen 12.479, und sie trotzdem vorzuschlagen wäre
 * ein Vorschlag, der meistens danebenliegt.
 *
 * Die verdächtigen Commits (R4) werden sich an derselben Stelle einreihen. Für
 * den Aufrufer ist das kein Unterschied — er bekommt eine geordnete Liste, und
 * woher ihre Reihenfolge stammt, ist die Sache dieser Klasse.
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

        $ownership = $this->ownership($request, $organization, $term);

        // Was das Regelwerk vorschlägt, steht nicht ein zweites Mal in der
        // Auswahlliste darunter — derselbe Name zweimal sieht nach einem Fehler
        // aus. `me` bleibt davon unberührt: es ist keine Wiederholung, sondern
        // eine andere Aussage („ich", nicht „diese Person").
        $taken = array_column($ownership, 'value');

        return response()->json([
            'suggestions' => [
                // Die Zuständigkeits-Regeln zuerst: wenn es eine Antwort auf
                // „wem gehört das?" gibt, ist sie die gesuchte.
                ...$ownership,
                // Dann der Betrachter selbst mit dem festen Text `me`:
                // „mir zuweisen" ist der häufigste Fall, und `me` bleibt
                // richtig, auch wenn sich die eigene Adresse ändert.
                ...$this->self($viewer, $term),
                // Dann die Teams: sie sind die wenigeren und die, die man
                // seltener ausgeschrieben im Kopf hat.
                ...$this->without($this->teams($organization, $term), $taken),
                ...$this->without($this->members($organization, $viewer, $term), $taken),
            ],
        ]);
    }

    /**
     * Was die Zuständigkeits-Regeln zu diesem Fehler sagen (R6).
     *
     * **Ohne `?issue=` gibt es nichts zu sagen**, und das ist der Regelfall bei
     * einer Sammelaktion. Ein Fehler, den der Betrachter nicht sehen darf oder
     * der einer anderen Organisation gehört, wird still übergangen: die Liste
     * ist eine Auswahlhilfe und nicht der Ort, an dem sich die Sichtbarkeit von
     * Einträgen erfragen lässt.
     *
     * @return list<array{value: string, label: string, kind: string}>
     */
    private function ownership(Request $request, Organization $organization, string $term): array
    {
        $id = $request->query('issue');

        if (! is_string($id) && ! is_int($id)) {
            return [];
        }

        $issue = Issue::query()->with('project.organization')->find((int) $id);
        $project = $issue?->project;

        if (
            ! $issue instanceof Issue
            || ! $project instanceof Project
            || $project->organization_id !== $organization->id
            || ! Gate::allows('view', $issue)
        ) {
            return [];
        }

        // Das zuletzt gesehene Ereignis: die Regeln greifen auf Pfade, Adressen
        // und Merkmale einer **Meldung** zu, und der Eintrag selbst trägt davon
        // nur den Titel. Das jüngste und nicht das erste, weil sich der Ort
        // eines Fehlers über die Zeit verschieben kann — nach einem Umbau steht
        // im ältesten Ereignis ein Pfad, den es nicht mehr gibt.
        $event = $issue->events()->latestFirst()->first();

        if (! $event instanceof Event) {
            return [];
        }

        $suggestions = app(OwnershipAssignment::class)
            ->suggest(OwnershipSubjects::fromEvent($event), $project);

        $found = [];

        foreach ($suggestions as $assignee) {
            if ($this->matches($assignee->label(), $term)) {
                $found[] = [
                    'value' => $assignee->term(),
                    'label' => $assignee->label(),
                    'kind' => 'ownership',
                ];
            }
        }

        return array_slice($found, 0, self::LIMIT);
    }

    /**
     * Dieselbe Liste ohne die bereits vorgeschlagenen Zuständigen.
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
