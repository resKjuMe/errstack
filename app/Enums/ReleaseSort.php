<?php

namespace App\Enums;

use App\Models\Issue;
use App\Models\Release;
use App\Support\Releases\Health\ReleaseHealth;
use App\Support\Releases\Health\SessionWindow;
use Illuminate\Database\Eloquent\Builder;

/**
 * Die Sortierung der Versionsliste.
 *
 * Bis R7 hatte die Liste genau eine sinnvolle Ordnung — die neueste zuerst —,
 * und eine Auswahl wäre Schmuck gewesen. Mit der Gesundheit kommt eine zweite
 * Frage dazu, die man an eine Versionsliste stellt und die von der Reihenfolge
 * abhängt: **welche Auslieferung ist die schlechteste?** Sie über fünfzig
 * Zeilen pro Seite selbst zu suchen ist keine Antwort.
 *
 * Eine Aufzählung und nicht ein durchgereichter Spaltenname: der Wert steht in
 * der Adresszeile und käme damit von außen. Sortierbar ist nur, was hier steht.
 *
 * **Sortiert wird in der Datenbank, nicht auf der Seite.** Die Kennzahlen einer
 * Zeile werden zwar erst für die fertige Seite gerechnet
 * ({@see ReleaseHealth::summarizeMany()}), aber
 * eine Sortierung, die nur die 50 gerade geholten Zeilen umstellt, wäre keine:
 * die schlechteste Version steht dann auf Seite vier und die Liste behauptet,
 * es sei die auf Seite eins. Deshalb hängen die Sortierungen dieselben Summen
 * als Unterabfrage an die Abfrage — aus demselben Ausschnitt
 * ({@see SessionWindow}), aus dem auch die angezeigten Zahlen kommen.
 */
enum ReleaseSort: string
{
    /** Die neueste Version zuerst — die Ansicht, mit der die Liste aufgeht. */
    case Newest = 'newest';

    /** Die älteste zuerst: „womit fing das an?" */
    case Oldest = 'oldest';

    /** Die mit den meisten neuen Fehlern zuerst. */
    case NewIssues = 'new_issues';

    /** Die mit der schlechtesten Crash-Free-Rate zuerst (R7). */
    case CrashFree = 'crash_free';

    /** Die verbreitetste zuerst — wo die meisten Menschen unterwegs sind. */
    case Adoption = 'adoption';

    public static function default(): self
    {
        return self::Newest;
    }

    public function label(): string
    {
        return __('releases.sort.'.$this->value);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $sort): array => ['value' => $sort->value, 'label' => $sort->label()],
            self::cases(),
        );
    }

    /**
     * Legt die Sortierung auf die Abfrage der Versionsliste.
     *
     * Jede Sortierung endet auf der Rangfolge der Versionen und damit auf `id`.
     * Das ist kein Schmuck: „keine neuen Fehler" und „keine Sitzungen" haben
     * Dutzende Versionen gemeinsam, und ohne einen eindeutigen letzten Schlüssel
     * wäre ihre Reihenfolge dem Zufall überlassen — beim Blättern erschiene
     * dieselbe Version auf zwei Seiten, während eine andere auf keiner steht.
     *
     * @param  Builder<Release>  $query
     */
    public function apply(Builder $query, SessionWindow $window): void
    {
        match ($this) {
            self::Newest => $query->newestFirst(),
            self::Oldest => $query->oldestFirst(),
            self::NewIssues => $this->byNewIssues($query, $window)->newestFirst(),
            self::CrashFree => $this->byCrashFree($query, $window)->newestFirst(),
            self::Adoption => $this->byAdoption($query, $window)->newestFirst(),
        };
    }

    /**
     * Nach neuen Fehlern: die Zahl, wegen der die Liste besteht.
     *
     * @param  Builder<Release>  $query
     * @return Builder<Release>
     */
    private function byNewIssues(Builder $query, SessionWindow $window): Builder
    {
        $counts = Issue::query()->toBase()
            ->selectRaw('first_release_id as release_id')
            ->selectRaw('count(*) as new_count')
            ->whereIn('project_id', $window->projectIds)
            ->whereNotNull('first_release_id')
            ->groupBy('first_release_id');

        return $query
            ->leftJoinSub($counts, 'sorted_issues', 'sorted_issues.release_id', '=', 'releases.id')
            // Eine Version ohne neue Fehler hat hier keine Zeile und damit
            // keinen Wert. Absteigend sortiert steht sie damit hinten — genau
            // dort, wo „keine neuen Fehler" hingehört.
            ->orderByDesc('sorted_issues.new_count');
    }

    /**
     * Nach Gesundheit: die schlechteste Auslieferung zuerst.
     *
     * Sortiert wird über den **Anteil der Abstürze**, absteigend — dieselbe
     * Ordnung wie „Crash-Free-Rate aufsteigend", aber ohne die Subtraktion in
     * der Anweisung. Eine Version ohne Sitzungen bekommt `-1` und landet damit
     * am Ende: sie ist nicht gesund, sondern unbekannt, und ganz oben in einer
     * Liste der schlechtesten Versionen hätte sie nichts verloren.
     *
     * @param  Builder<Release>  $query
     * @return Builder<Release>
     */
    private function byCrashFree(Builder $query, SessionWindow $window): Builder
    {
        return $this->withSessions($query, $window)
            ->orderByRaw(
                'case when coalesce(release_sessions.session_count, 0) > 0'
                .' then release_sessions.crashed_count * 1.0 / release_sessions.session_count'
                .' else -1 end desc'
            );
    }

    /**
     * Nach Verbreitung: wo die meisten unterwegs sind.
     *
     * **Über Sitzungen und nicht über Menschen**, obwohl die Anzeige beide
     * kennt. Die Zahl über Menschen braucht eine Nutzerkennung in den Meldungen,
     * und die schickt nicht jedes SDK; eine danach sortierte Liste stellte
     * „schickt keine Kennung" und „hat kaum Nutzer" nebeneinander, ohne dass man
     * beides auseinanderhalten könnte.
     *
     * @param  Builder<Release>  $query
     * @return Builder<Release>
     */
    private function byAdoption(Builder $query, SessionWindow $window): Builder
    {
        $totals = $window->sessionsByProject();

        return $this->withSessions($query, $window)
            ->leftJoinSub($totals, 'project_sessions', 'project_sessions.project_id', '=', 'releases.project_id')
            // Der Anteil und nicht die nackte Zahl: die Liste zeigt mehrere
            // Projekte nebeneinander, und die größte Version eines kleinen
            // Projekts hat weniger Sitzungen als die unbedeutendste eines
            // großen.
            ->orderByRaw(
                'case when coalesce(project_sessions.session_count, 0) > 0'
                .' then coalesce(release_sessions.session_count, 0) * 1.0 / project_sessions.session_count'
                .' else -1 end desc'
            );
    }

    /**
     * Die Sitzungssummen je Version an der Abfrage.
     *
     * @param  Builder<Release>  $query
     * @return Builder<Release>
     */
    private function withSessions(Builder $query, SessionWindow $window): Builder
    {
        return $query->leftJoinSub(
            $window->sessionsByRelease(),
            'release_sessions',
            'release_sessions.release_id',
            '=',
            'releases.id',
        );
    }
}
