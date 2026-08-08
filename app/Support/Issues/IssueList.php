<?php

namespace App\Support\Issues;

use App\Enums\IssueSort;
use App\Enums\IssueStatus;
use App\Models\Issue;
use App\Models\Project;
use App\Support\Filters\GlobalFilter;
use App\Support\Formats;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Die Fehlerliste: Abfrage und Darstellung einer Seite.
 *
 * **Sie liest ausschließlich die Zähler am Eintrag** (`times_seen`,
 * `users_seen`, `first_seen`, `last_seen`) und die Zeitreihe aus
 * {@see IssueSeries} — nie die Einzelereignisse. Das ist die Zusage dieser
 * Aufgabe und zugleich der Grund, warum die Liste bei einer Million Meldungen
 * dieselbe Abfrage bleibt: gezählt wurde beim Aufnehmen, hier wird nur noch
 * gelesen. Ein `count(*)` über die Ereignisse an dieser Stelle sähe in einem
 * Testbestand harmlos aus und wäre im Betrieb der Grund, warum die Seite nicht
 * mehr aufgeht.
 */
final class IssueList
{
    /**
     * Einträge je Seite.
     *
     * Fünfzig wie im Änderungsprotokoll: genug, dass man scrollt statt blättert,
     * und wenig genug, dass die Verlaufsgrafiken einer Seite in eine Abfrage
     * passen.
     */
    public const PER_PAGE = 50;

    /**
     * Eine Seite der Liste, fertig für die Oberfläche.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public static function paginate(GlobalFilter $filter, IssueSort $sort, ?IssueStatus $status): LengthAwarePaginator
    {
        $page = self::query($filter, $sort, $status)
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        // Die Verlaufsgrafiken **vor** dem Umwandeln, denn danach sind die
        // Einträge Felder und keine Modelle mehr — und die Kennungen der Seite
        // sind genau die, für die eine Reihe gebraucht wird.
        $ids = $page->getCollection()->map(fn (Issue $issue): int => $issue->id)->values()->all();

        $series = IssueSeries::forIssues($ids, $filter);

        $page->through(fn (Issue $issue): array => self::present($issue, $series[$issue->id] ?? []));

        return $page;
    }

    /**
     * Die Abfrage hinter der Liste.
     *
     * Das Projekt wird mitgeladen, weil die Liste über mehrere Projekte gehen
     * kann und jede Zeile dann sagen muss, zu welchem sie gehört — ohne das wäre
     * es je Zeile eine Abfrage, also fünfzig für eine Seite.
     *
     * @return Builder<Issue>
     */
    public static function query(GlobalFilter $filter, IssueSort $sort, ?IssueStatus $status): Builder
    {
        $query = Issue::query()->with(['project:id,name,slug,organization_id', 'project.organization:id,slug']);

        $filter->overlapping($query);

        if ($status !== null) {
            $query->where('status', $status);
        }

        $sort->apply($query);

        return $query;
    }

    /**
     * Eine Zeile.
     *
     * Zahlen kommen doppelt: einmal roh und einmal geschrieben. Die rohe Zahl
     * ist das, was die Oberfläche vergleicht und in die Grafik legt, die
     * geschriebene das, was dasteht — und wie eine Zahl dasteht, entscheidet die
     * Sprache und nicht der Browser.
     *
     * @param  list<int>  $series
     * @return array<string, mixed>
     */
    private static function present(Issue $issue, array $series): array
    {
        return [
            'id' => $issue->id,
            // Ein Eintrag ohne Titel ist kein Fehler in den Daten: eine bloße
            // Meldung ohne Ausnahme hat keinen. Die Fehlerstelle ist dann die
            // aussagekräftigste Angabe, die es gibt.
            'title' => $issue->title ?? $issue->culprit ?? __('issues.list.untitled'),
            'culprit' => $issue->culprit,
            'type' => $issue->type,
            'level' => $issue->level->value,
            'levelLabel' => $issue->level->label(),
            'status' => $issue->status->value,
            'statusLabel' => $issue->status->label(),
            'priority' => $issue->priority->value,
            'priorityLabel' => $issue->priority->label(),
            'timesSeen' => $issue->times_seen,
            'timesSeenLabel' => Formats::number($issue->times_seen),
            'usersSeen' => $issue->users_seen,
            'usersSeenLabel' => Formats::number($issue->users_seen),
            'firstSeen' => $issue->first_seen->toIso8601String(),
            'firstSeenLabel' => Formats::dateTime($issue->first_seen),
            'lastSeen' => $issue->last_seen->toIso8601String(),
            'lastSeenLabel' => Formats::dateTime($issue->last_seen),
            'project' => self::project($issue),
            'series' => $series,
        ];
    }

    /**
     * Das Projekt der Zeile — samt Link auf seine Seite.
     *
     * Sichtbar ist es nur, wenn die Liste über mehrere Projekte geht; die
     * Nutzlast trägt es trotzdem immer, damit die Oberfläche nicht zwei Formen
     * einer Zeile kennen muss.
     *
     * @return array{name: string, slug: string, href: string}|null
     */
    private static function project(Issue $issue): ?array
    {
        $project = $issue->project;

        if (! $project instanceof Project) {
            return null;
        }

        return [
            'name' => $project->name,
            'slug' => $project->slug,
            'href' => route('projects.show', [$project->organization, $project]),
        ];
    }
}
