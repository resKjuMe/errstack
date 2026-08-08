<?php

namespace App\Support\Issues;

use App\Enums\CountPeriod;
use App\Models\Issue;
use App\Models\IssueCount;
use App\Support\Filters\GlobalFilter;
use Carbon\CarbonImmutable;

/**
 * Die Verlaufsgrafik der Fehlerliste: je Eintrag eine Reihe von Zahlen, alle
 * über demselben Raster.
 *
 * **Eine Abfrage für die ganze Seite, nicht eine je Zeile.** Fünfzig Zeilen mit
 * je einer Abfrage sind fünfzig Umläufe zur Datenbank, und die Grafik wäre der
 * teuerste Teil einer Liste, in der sie das kleinste Element ist.
 *
 * **Dasselbe Raster für alle Zeilen.** Die Fenster kommen aus dem gewählten
 * Zeitraum und nicht aus den vorhandenen Zeilen: ein Eintrag mit drei Ausschlägen
 * und einer mit dreißig sollen untereinander vergleichbar sein. Fehlende Fenster
 * sind eine Null und keine Lücke — ein Verlauf, der die stillen Stunden
 * weglässt, sieht aus wie ein Dauerfeuer.
 *
 * Gelesen werden ausschließlich die Zähler aus {@see IssueCount}; die
 * Einzelereignisse bleiben unberührt. Das ist nicht nur schneller, es ist auch
 * der einzige Weg, der nach dem Aufräumen der Meldungen noch funktioniert.
 */
final class IssueSeries
{
    /**
     * Ab dieser Zeitraum-Länge wird in Tagen statt in Stunden gezeichnet.
     *
     * Drei Tage sind 72 Balken — die Grenze dessen, was in einer Grafik von
     * Daumenbreite noch etwas zeigt. Darüber verschmelzen die Stunden ohnehin
     * zu einem Strich, und der Tag sagt mehr.
     */
    private const HOURLY_LIMIT_HOURS = 72;

    /**
     * Die Reihen der übergebenen Einträge, nach Eintrags-Kennung.
     *
     * @param  list<int>  $issueIds
     * @return array<int, list<int>>
     */
    public static function forIssues(array $issueIds, GlobalFilter $filter): array
    {
        if ($issueIds === []) {
            return [];
        }

        $period = self::periodFor($filter);
        $windows = self::windows($period, $filter);

        if ($windows === []) {
            return [];
        }

        $empty = array_fill(0, count($windows), 0);
        $index = array_flip($windows);

        /** @var array<int, list<int>> $series */
        $series = array_fill_keys($issueIds, $empty);

        // Zusammengeführte Einträge (S9) zeichnen die Reihe ihrer Untergruppen
        // mit: die Zeitreihe bleibt beim Beitritt stehen, wo sie entstanden ist,
        // und ein Kopf, der nur seine eigene zeigte, hätte einen Verlauf, der
        // nicht zu seiner Häufigkeit passt.
        $owner = self::owners($issueIds);

        $rows = IssueCount::query()
            ->whereIn('issue_id', array_keys($owner))
            ->where('period', $period)
            // Die Grenzen ausdrücklich in UTC gelesen: die Fenster sind in UTC
            // abgelegt, und `parse()` ohne Zone nähme die der Anwendung — eine
            // um Stunden verschobene Grafik wäre das Ergebnis, und zwar eine,
            // die plausibel aussieht.
            ->whereBetween('window_start', [
                CarbonImmutable::parse($windows[0], 'UTC'),
                CarbonImmutable::parse($windows[array_key_last($windows)], 'UTC'),
            ])
            ->get(['issue_id', 'window_start', 'event_count']);

        foreach ($rows as $row) {
            $slot = $index[$row->window_start->utc()->format('Y-m-d H:i:s')] ?? null;

            if ($slot !== null) {
                // Aufaddiert und nicht gesetzt: mehrere Untergruppen können im
                // selben Fenster gezählt haben, und der Kopf selbst auch.
                $series[$owner[$row->issue_id]][$slot] += $row->event_count;
            }
        }

        return $series;
    }

    /**
     * Zu welcher Reihe jede gelesene Zeile gehört — Kennung des Zählers auf
     * Kennung des Eintrags, der ihn zeichnet.
     *
     * Ein Eintrag steht darin auf sich selbst, eine Untergruppe auf ihren Kopf.
     * Eine Abfrage für die ganze Seite: je Zeile die Untergruppen zu holen wären
     * fünfzig Umläufe für eine Grafik von Daumenbreite — dieselbe Rechnung wie
     * bei den Zählern selbst.
     *
     * @param  list<int>  $issueIds
     * @return array<int, int>
     */
    private static function owners(array $issueIds): array
    {
        $owner = array_combine($issueIds, $issueIds);

        $members = Issue::query()
            ->whereIn('merged_into_id', $issueIds)
            ->pluck('merged_into_id', 'id');

        foreach ($members as $memberId => $headId) {
            $owner[(int) $memberId] = (int) $headId;
        }

        return $owner;
    }

    /**
     * Die Auflösung, in der gezeichnet wird.
     */
    public static function periodFor(GlobalFilter $filter): CountPeriod
    {
        return $filter->fromUtc()->diffInHours($filter->toUtc()) > self::HOURLY_LIMIT_HOURS
            ? CountPeriod::Day
            : CountPeriod::Hour;
    }

    /**
     * Die Fenster des Rasters, aufsteigend, als Zeichenketten in UTC.
     *
     * Zeichenketten und keine Zeitpunkte, weil sie hier nur als Schlüssel
     * dienen: der Vergleich zweier Zeitpunkte über Zeitzonen- und
     * Genauigkeitsgrenzen hinweg ist die Sorte Fehler, die man erst an einer
     * verschobenen Grafik bemerkt.
     *
     * @return list<string>
     */
    private static function windows(CountPeriod $period, GlobalFilter $filter): array
    {
        $cursor = $period->windowFor(self::start($period, $filter));
        $last = $period->windowFor($filter->toUtc());

        $windows = [];

        while ($cursor->lessThanOrEqualTo($last)) {
            $windows[] = $cursor->format('Y-m-d H:i:s');

            $cursor = $period === CountPeriod::Hour ? $cursor->addHour() : $cursor->addDay();
        }

        return $windows;
    }

    /**
     * Der Anfang des Rasters — nie weiter zurück, als es Zähler gibt.
     *
     * Ohne diese Grenze bestimmt die Adresszeile die Größe des Rasters: ein
     * eigener Zeitraum ist auf zwei Datumsfelder geprüft, aber nicht auf seine
     * Länge, und `from=1900-01-01` wären rund 46.000 Tagesfenster — je Zeile der
     * Seite ein Feld dieser Länge, für eine Grafik von Daumenbreite. Genau das
     * ist die Sorte Anfrage, mit der man eine Seite umlegt, ohne etwas
     * anzugreifen.
     *
     * Die Grenze ist keine gegriffene Zahl, sondern die Aufbewahrung der Zähler
     * ({@see CountPeriod::retentionDays()}): davor gibt es nichts zu zeichnen,
     * die zusätzlichen Fenster wären Nullen.
     */
    private static function start(CountPeriod $period, GlobalFilter $filter): CarbonImmutable
    {
        $earliest = CarbonImmutable::now('UTC')->subDays($period->retentionDays());
        $from = $filter->fromUtc();

        return $from->lessThan($earliest) ? $earliest : $from;
    }
}
