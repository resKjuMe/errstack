<?php

namespace App\Support\Tags;

use App\Models\Issue;
use App\Models\IssueTag;
use App\Models\IssueTagKey;
use App\Models\ProjectTag;
use App\Models\ProjectTagKey;
use App\Support\Formats;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;

/**
 * Die Merkmal-Verteilung, wie sie angezeigt wird: je Merkmal die häufigsten
 * Werte mit Anzahl und Anteil.
 *
 * **Gelesen werden ausschließlich die vorberechneten Zähler**
 * ({@see TagAggregates}), nie die Einzelereignisse. Dieselbe Zusage wie bei der
 * Fehlerliste (S1) und aus demselben Grund: über die Ereignisse wäre jede dieser
 * Fragen eine Gruppierung über alles, was ein Fehler je an Meldungen hatte.
 *
 * **Der Zeitraum der Filterleiste wirkt hier nicht.** Ein Merkmal-Zähler ist
 * über die ganze Lebensdauer gezählt; ihn nach Zeit zu schneiden ginge nur über
 * die Einzelereignisse — und die sind für diese Abfragen tabu. Die Ansicht sagt
 * das, statt die Auswahl still zu übergehen.
 *
 * **Die Prozentangabe hat einen eigenen Nenner.** Sie rechnet gegen den Zähler
 * am Merkmal und nicht gegen die Summe der angezeigten Werte: Letzteres ergäbe
 * immer 100 %, auch wenn die Hälfte der Werte gar nicht mehr aufgehoben wird
 * ({@see TagAggregates::MAX_VALUES_PER_KEY}). Was fehlt, steht als „übrige"
 * daneben.
 */
final class TagFacets
{
    /** Werte je Merkmal in der Übersicht. Alle weiteren zeigt die Detailseite. */
    public const TOP_VALUES = 5;

    /**
     * Merkmale in der Übersicht.
     *
     * Die Begrenzung hält die eine Abfrage der Werte klein: sie holt höchstens
     * diese Zahl mal {@see TagAggregates::MAX_VALUES_PER_KEY} Zeilen.
     */
    public const TOP_KEYS = 24;

    /**
     * Die Merkmale eines Fehler-Eintrags.
     *
     * @return list<array<string, mixed>>
     */
    public static function forIssue(Issue $issue, int $topValues = self::TOP_VALUES): array
    {
        return self::overview(
            self::issueKeys($issue),
            self::issueValues($issue),
            $topValues,
        );
    }

    /**
     * Alle Werte **eines** Merkmals eines Fehler-Eintrags.
     *
     * @return array<string, mixed>|null `null`, wenn der Eintrag dieses Merkmal nicht kennt
     */
    public static function forIssueKey(Issue $issue, string $key): ?array
    {
        return self::detail(
            self::issueKeys($issue)->where('tag_key', $key),
            self::issueValues($issue)->where('tag_key', $key),
            $key,
        );
    }

    /**
     * Die Merkmale mehrerer Projekte, über sie hinweg summiert.
     *
     * @param  list<int>  $projectIds
     * @return list<array<string, mixed>>
     */
    public static function forProjects(array $projectIds, int $topValues = self::TOP_VALUES): array
    {
        if ($projectIds === []) {
            return [];
        }

        return self::overview(
            self::projectKeys($projectIds),
            self::projectValues($projectIds),
            $topValues,
        );
    }

    /**
     * Alle Werte **eines** Merkmals über mehrere Projekte.
     *
     * @param  list<int>  $projectIds
     * @return array<string, mixed>|null
     */
    public static function forProjectsKey(array $projectIds, string $key): ?array
    {
        if ($projectIds === []) {
            return null;
        }

        return self::detail(
            self::projectKeys($projectIds)->where('tag_key', $key),
            self::projectValues($projectIds)->where('tag_key', $key),
            $key,
        );
    }

    /**
     * Der Name eines Merkmals in der Sprache des Betrachters.
     *
     * Bekannte Merkmale bekommen einen geschriebenen Namen („Betriebssystem"),
     * selbst gesetzte behalten ihren — eine Marke, die eine Anwendung
     * `mandant` nennt, heißt hier `mandant` und nicht „Unbekannt".
     */
    public static function label(string $key): string
    {
        // Nur die festen Merkmale einer Meldung bekommen einen geschriebenen
        // Namen. Die Prüfung gegen die Liste und nicht bloß gegen das
        // Sprachverzeichnis ist Absicht: eine selbst gesetzte Marke, die
        // zufällig `browser_name` heißt, soll nicht „Browser (ohne Fassung)"
        // heißen — sie ist etwas anderes.
        if (! in_array($key, EventTags::RESERVED, true)) {
            return $key;
        }

        // Der Punkt in `browser.name` wird zum Unterstrich: Laravel liest einen
        // Punkt im Schlüssel als Weg in eine tiefere Ebene, und der Schlüssel
        // käme als roher Text in der Oberfläche an.
        $line = 'tags.keys.'.str_replace('.', '_', $key);

        return Lang::has($line) ? __($line) : $key;
    }

    /**
     * Übersicht: je Merkmal die häufigsten Werte.
     *
     * Zwei Abfragen für die ganze Seite — eine für die Nenner, eine für die
     * Werte. Je Merkmal eine wären bei zwei Dutzend Merkmalen zwei Dutzend
     * Umläufe zur Datenbank, für eine Seite, die man überfliegt.
     *
     * @param  Builder  $keys  Merkmale samt Nenner, häufigste zuerst
     * @param  Builder  $values  alle Werte dieser Ebene
     * @return list<array<string, mixed>>
     */
    private static function overview(Builder $keys, Builder $values, int $topValues): array
    {
        $keyRows = $keys->limit(self::TOP_KEYS)->get();

        if ($keyRows->isEmpty()) {
            return [];
        }

        $names = $keyRows->pluck('tag_key')->all();

        $grouped = $values->whereIn('tag_key', $names)->get()->groupBy('tag_key');

        $facets = [];

        foreach ($keyRows as $key) {
            $rows = ($grouped[$key->tag_key] ?? collect())
                ->sortByDesc('times_seen')
                ->values();

            $shown = $rows->take($topValues);
            $total = (int) $key->times_seen;

            $facets[] = [
                'key' => (string) $key->tag_key,
                'label' => self::label((string) $key->tag_key),
                'total' => $total,
                'totalLabel' => Formats::number($total),
                'valueCount' => $rows->count(),
                'values' => $shown->map(fn ($row): array => self::value($row, $total))->all(),
                // Was nicht in den ersten Werten steht — die selteneren und das,
                // was die Obergrenze aussortiert hat. Beides zusammen, weil es
                // in der Übersicht dieselbe Auskunft ist: „da ist noch mehr".
                'rest' => self::rest($total, (int) $shown->sum('times_seen')),
            ];
        }

        return $facets;
    }

    /**
     * Detail: alle Werte eines Merkmals.
     *
     * @return array<string, mixed>|null
     */
    private static function detail(Builder $keys, Builder $values, string $key): ?array
    {
        $row = $keys->first();

        if ($row === null) {
            return null;
        }

        $total = (int) $row->times_seen;

        $rows = $values->orderByDesc('times_seen')->get();

        return [
            'key' => $key,
            'label' => self::label($key),
            'total' => $total,
            'totalLabel' => Formats::number($total),
            'valueCount' => $rows->count(),
            'values' => $rows->map(fn ($value): array => self::value($value, $total))->all(),
            // Hier ist der Rest eindeutig: alle Werte stehen da, was fehlt, wurde
            // nie aufgehoben.
            'rest' => self::rest($total, (int) $rows->sum('times_seen')),
            'capped' => $rows->count() >= TagAggregates::MAX_VALUES_PER_KEY,
        ];
    }

    /**
     * Ein Wert samt Anteil.
     *
     * @return array<string, mixed>
     */
    private static function value(object $row, int $total): array
    {
        $count = (int) $row->times_seen;

        return [
            'value' => (string) $row->tag_value,
            'count' => $count,
            'countLabel' => Formats::number($count),
            'share' => self::share($count, $total),
            'shareLabel' => self::shareLabel($count, $total),
        ];
    }

    /**
     * Was neben den aufgezählten Werten noch übrig ist — oder `null`, wenn
     * nichts.
     *
     * @return array<string, mixed>|null
     */
    private static function rest(int $total, int $shown): ?array
    {
        $rest = $total - $shown;

        if ($rest <= 0) {
            return null;
        }

        return [
            'count' => $rest,
            'countLabel' => Formats::number($rest),
            'share' => self::share($rest, $total),
            'shareLabel' => self::shareLabel($rest, $total),
        ];
    }

    private static function share(int $count, int $total): float
    {
        return $total <= 0 ? 0.0 : round($count / $total * 100, 1);
    }

    /**
     * Der Anteil, wie er dasteht.
     *
     * Geschrieben wird er hier und nicht im Browser: wie eine Zahl aussieht,
     * entscheidet die Sprache, und die kennt der Server.
     */
    private static function shareLabel(int $count, int $total): string
    {
        return Formats::number(self::share($count, $total), 1).' %';
    }

    private static function issueKeys(Issue $issue): Builder
    {
        return DB::table((new IssueTagKey)->getTable())
            ->where('issue_id', $issue->id)
            ->orderByDesc('times_seen')
            ->orderBy('tag_key')
            ->select(['tag_key', 'times_seen', 'value_count']);
    }

    private static function issueValues(Issue $issue): Builder
    {
        return DB::table((new IssueTag)->getTable())
            ->where('issue_id', $issue->id)
            ->select(['tag_key', 'tag_value', 'times_seen']);
    }

    /**
     * Die Merkmale mehrerer Projekte — über sie hinweg summiert.
     *
     * Die Summe ist nötig, weil die Filterleiste mehrere Projekte zugleich
     * meinen kann: „Chrome" ist dann die Summe aus allen, und nicht die des
     * ersten.
     *
     * @param  list<int>  $projectIds
     */
    private static function projectKeys(array $projectIds): Builder
    {
        return DB::table((new ProjectTagKey)->getTable())
            ->whereIn('project_id', $projectIds)
            ->groupBy('tag_key')
            ->orderByDesc(DB::raw('sum(times_seen)'))
            ->orderBy('tag_key')
            ->select(['tag_key', DB::raw('sum(times_seen) as times_seen'), DB::raw('sum(value_count) as value_count')]);
    }

    /**
     * @param  list<int>  $projectIds
     */
    private static function projectValues(array $projectIds): Builder
    {
        return DB::table((new ProjectTag)->getTable())
            ->whereIn('project_id', $projectIds)
            ->groupBy('tag_key', 'tag_value')
            ->select(['tag_key', 'tag_value', DB::raw('sum(times_seen) as times_seen')]);
    }
}
