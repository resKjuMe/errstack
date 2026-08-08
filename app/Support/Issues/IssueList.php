<?php

namespace App\Support\Issues;

use App\Enums\IssueCategory;
use App\Enums\IssueSort;
use App\Enums\IssueStatus;
use App\Models\Issue;
use App\Models\Project;
use App\Support\Filters\GlobalFilter;
use App\Support\Formats;
use App\Support\Search\SearchExpression;
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
 *
 * **Die einzige Ausnahme trägt der Suchende selbst ein.** `user.email:` fragt
 * nach etwas, das nur am Ereignis steht, und geht deshalb dorthin
 * ({@see IssueFields}). Sie kostet, was eine solche Frage kostet — aber nur, wer
 * sie stellt, bezahlt sie: ohne diesen Begriff bleibt die Liste eine Abfrage auf
 * den Zählern.
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
    public static function paginate(GlobalFilter $filter, IssueSort $sort, ?IssueStatus $status, ?SearchExpression $search = null): LengthAwarePaginator
    {
        $page = self::query($filter, $sort, $status, $search)
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
    public static function query(GlobalFilter $filter, IssueSort $sort, ?IssueStatus $status, ?SearchExpression $search = null): Builder
    {
        $query = Issue::query()->with([
            'project:id,name,slug,organization_id',
            'project.organization:id,slug',
            // Die beiden Versionen mitgeladen: ohne sie wäre „zuerst gesehen
            // in" je Zeile eine Abfrage, also fünfzig für eine Seite.
            'firstRelease:id,version',
            'lastRelease:id,version',
        ])
            // Die Zahl der von Hand zusammengeführten Untergruppen (S9) — als
            // Unterabfrage in derselben Anweisung. Sie mitzuladen wäre je Zeile
            // eine Abfrage, und die Untergruppen selbst braucht die Liste nicht:
            // sie zeigt nur, dass es welche gibt.
            ->withCount('mergedSources');

        // Nur Fehler. Seit PF6 teilen sich Fehler und Leistungsprobleme die
        // Tabelle, und ohne diese Zeile stünden langsame Abfragen zwischen den
        // Ausnahmen. Sie steht hier und nicht im Aufrufer, damit es die
        // **Fehlerliste** ist, die keine Leistungsprobleme zeigt, und nicht
        // jede Ansicht, die zufällig daran gedacht hat.
        $query->ofCategory(IssueCategory::Error);

        $filter->overlapping($query);

        // Untergruppen stehen nicht für sich: ihre Zahlen sind im Kopf
        // enthalten, und daneben ein zweites Mal einzeln zu erscheinen wäre
        // genau die Doppelzählung, gegen die jemand sie zusammengeführt hat (S9).
        $query->standalone();

        if ($status !== null) {
            $query->where('status', $status);
        }

        $search?->apply($query);

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
            // Der Weg in die Tiefe: die Zeile nennt den Fehler, die Detailseite
            // sagt, warum es ihn gibt.
            'href' => route('issues.show', $issue),
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
            // Die betroffenen Versionen. `null`, solange keine Meldung eine
            // mitgebracht hat — der Normalfall bei einem SDK ohne
            // `release`-Angabe, und deshalb kein Fehlerfall für die Oberfläche.
            'firstRelease' => $issue->firstRelease?->version,
            'lastRelease' => $issue->lastRelease?->version,
            'project' => self::project($issue),
            // Der Weg zu den Merkmalen dieses Fehlers — welche Browser, welche
            // Fassungen, welche Server ihn betrifft (S3).
            'tagsHref' => route('issues.tags.index', $issue),
            // Wie viele Untergruppen von Hand zusammengeführt wurden (S9). Die
            // Zahl steht in der Zeile, weil die Zahlen daneben sonst
            // unerklärlich wären: ein Eintrag mit drei Fingerabdrücken zählt
            // anders als einer mit einem, und der Unterschied ist nichts, was
            // man erst auf der Detailseite erfahren sollte.
            'mergedCount' => $issue->merged_sources_count ?? 0,
            // Von selbst zurückgekommen (S8). Die Marke gehört in die Zeile und
            // nicht erst auf die Detailseite: ein Fehler, der schon einmal als
            // behoben galt, ist eine andere Nachricht als einer, der noch nie
            // erledigt war — und die Zeile ist die Stelle, an der jemand
            // entscheidet, was er sich zuerst ansieht.
            'regressed' => $issue->hasRegressed(),
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
