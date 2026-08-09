<?php

namespace App\Support\Releases\Health;

use App\Enums\CountPeriod;
use App\Models\Release;
use App\Models\ReleaseSessionCount;
use App\Support\Formats;
use App\Support\Issues\IssueSeries;
use App\Support\Performance\Trends\TrendSeries;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

/**
 * Der Verlauf einer Auslieferung: wie sie sich ausgebreitet hat.
 *
 * Die eine Zahl „38 % Verbreitung" beantwortet die Frage nach dem Ausrollen
 * nicht — **steigt sie noch, oder steht sie?** Ein Ausrollen, das bei einem
 * Drittel hängen bleibt, sieht in der Momentaufnahme aus wie eines, das gerade
 * erst begonnen hat. Deshalb die Kurve.
 *
 * Gezeigt wird der **Anteil** und nicht die nackte Sitzungszahl: die schwankt
 * mit der Tageszeit, und eine Kurve, die nachts einbricht, sähe aus wie ein
 * zurückgenommenes Ausrollen. Der Anteil an allen Sitzungen des Projekts im
 * selben Fenster hat diesen Atem nicht.
 *
 * **Zwei Abfragen, gerastert in der Datenbank.** Die Zähler liegen minutenweise
 * vor ({@see ReleaseSessionCount::BUCKET_SECONDS}); über 90 Tage
 * wären das 130.000 Zeilen je Reihe, die niemand in den Speicher holen will.
 * Gerastert wird über den **Text** des Zeitstempels — dieselbe Schreibweise wie
 * bei den Antwortzeit-Verläufen
 * ({@see TrendSeries}) und aus demselben Grund:
 * `strftime` gibt es nur in SQLite, `date_format` nur in MySQL, `substr` in
 * beiden.
 */
final class ReleaseAdoptionSeries
{
    /**
     * Ab dieser Zeitraum-Länge wird in Tagen statt in Stunden gezeichnet.
     *
     * Dieselbe Grenze wie bei den Verlaufsgrafiken der Fehlerliste
     * ({@see IssueSeries}) — zwei Grafiken auf benachbarten
     * Seiten mit verschiedenen Rastern wären zwei Bilder, die man nicht
     * nebeneinanderlegen kann.
     */
    private const HOURLY_LIMIT_HOURS = 72;

    /**
     * Der Verlauf, fertig für die Oberfläche.
     *
     * Ein Fenster ohne eine einzige Sitzung des Projekts hat **keinen** Anteil
     * und nicht „0 %": in einer Nacht ohne Verkehr ist die Verbreitung nicht auf
     * null gefallen, sie ist unbekannt. Die Grafik unterbricht dort die Linie,
     * statt sie auf den Boden zu ziehen.
     *
     * @return array<string, mixed>
     */
    public static function present(Release $release, SessionWindow $window): array
    {
        $period = self::periodFor($window);

        $own = self::sums($window->counts()->where('release_id', $release->getKey()), $period);
        $all = self::sums($window->counts(), $period);

        $points = [];

        foreach (self::windows($period, $window) as $key => $at) {
            $total = $all[$key] ?? 0;
            $mine = $own[$key] ?? 0;

            $points[] = [
                'at' => $at->toIso8601String(),
                'atLabel' => Formats::dateTime($at),
                'value' => $total === 0 ? null : round($mine / $total * 100, 2),
                'valueLabel' => $total === 0 ? null : Formats::number($mine / $total * 100, 1).' %',
                'sessions' => $mine,
                'projectSessions' => $total,
            ];
        }

        return [
            'points' => $points,
            // Ob überhaupt etwas zu sehen ist. Die Oberfläche unterscheidet
            // damit „diese Version hat keine Sitzungen" von „in diesem Zeitraum
            // war im ganzen Projekt nichts los" — zwei Lagen, die als leere
            // Grafik gleich aussähen und völlig Verschiedenes bedeuten.
            'hasData' => array_sum(array_column($points, 'sessions')) > 0,
            'hasProjectData' => array_sum(array_column($points, 'projectSessions')) > 0,
        ];
    }

    /**
     * Die Auflösung: Stunden für kurze Zeiträume, Tage für lange.
     */
    private static function periodFor(SessionWindow $window): CountPeriod
    {
        $hours = $window->from->utc()->diffInHours($window->to->utc());

        return $hours > self::HOURLY_LIMIT_HOURS ? CountPeriod::Day : CountPeriod::Hour;
    }

    /**
     * Das Raster: jeder Abschnitt des Zeitraums, auch die leeren.
     *
     * Aufgezählt und nicht aus den gefundenen Zeilen abgeleitet — sonst hätte
     * eine Kurve mit einer Betriebspause darin an dieser Stelle keinen Punkt,
     * sondern gar keine Lücke: die beiden Nachbarn rückten zusammen, und aus
     * einem stillen Wochenende würde ein nahtloser Verlauf.
     *
     * @return array<string, CarbonImmutable> Rasterschlüssel auf Zeitpunkt
     */
    private static function windows(CountPeriod $period, SessionWindow $window): array
    {
        $cursor = $period->windowFor($window->from->utc());
        $last = $period->windowFor($window->to->utc());

        $windows = [];

        while ($cursor->lessThanOrEqualTo($last)) {
            $windows[self::key($period, $cursor)] = $cursor;

            $cursor = $period === CountPeriod::Hour ? $cursor->addHour() : $cursor->addDay();
        }

        return $windows;
    }

    /**
     * Sitzungen je Rasterabschnitt.
     *
     * @return array<string, int>
     */
    private static function sums(Builder $query, CountPeriod $period): array
    {
        $rows = $query
            ->selectRaw('substr(bucket_start, 1, '.self::keyLength($period).') as window_key')
            ->selectRaw('sum(session_count) as sessions')
            ->groupBy('window_key')
            ->get();

        $sums = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $values */
            $values = (array) $row;

            $sums[(string) $values['window_key']] = (int) $values['sessions'];
        }

        return $sums;
    }

    /**
     * Der Rasterschlüssel eines Zeitpunkts — dieselbe Zeichenkette, die die
     * Datenbank aus `bucket_start` schneidet.
     */
    private static function key(CountPeriod $period, CarbonImmutable $at): string
    {
        return substr($at->utc()->format('Y-m-d H:i:s'), 0, self::keyLength($period));
    }

    /**
     * `2026-03-10 14` für die Stunde, `2026-03-10` für den Tag.
     */
    private static function keyLength(CountPeriod $period): int
    {
        return $period === CountPeriod::Hour ? 13 : 10;
    }
}
