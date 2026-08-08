<?php

namespace App\Support\Releases;

use App\Enums\CountPeriod;
use App\Models\Deploy;
use App\Support\Filters\GlobalFilter;
use App\Support\Formats;
use App\Support\Issues\IssueSeries;
use Carbon\CarbonImmutable;

/**
 * Die Deploy-Markierungen einer Verlaufsgrafik: an welcher Stelle des Rasters
 * ausgeliefert wurde.
 *
 * Der Zweck ist eine einzige Frage — **hängt der Ausschlag mit der
 * Auslieferung zusammen?** Sie wird nach jeder Störung gestellt und ist ohne
 * Markierung nur zu beantworten, indem jemand in einem zweiten Fenster die
 * Versionsliste aufschlägt und Zeitpunkte vergleicht. Genau deshalb steht der
 * Strich in der Grafik und nicht daneben.
 *
 * **Die Markierungen liegen auf demselben Raster wie die Zahlen** ({@see
 * IssueSeries}): eine Auslieferung fällt in das Fenster, in dem sie stattfand,
 * und bekommt dessen Nummer. Ein eigenes Raster wäre die Sorte Genauigkeit, die
 * in einer Grafik von Daumenbreite niemand sieht — und die beim ersten
 * Zeitzonen-Fehler eine Markierung neben ihren Ausschlag legt.
 *
 * **Sie gelten für genau eine Umgebung.** Ein `staging`-Deploy erklärt keinen
 * Ausschlag in der Produktion; beide nebeneinander zu zeigen wäre ein Wald aus
 * Strichen, aus dem sich nichts mehr ablesen lässt. Welche gemeint ist,
 * entscheidet die Filterleiste — und ohne Auswahl die Standard-Umgebung des
 * Projekts, also die, in der eine Auslieferung „draußen" bedeutet.
 */
final class DeployMarkers
{
    /**
     * Höchstens so viele Markierungen je Grafik.
     *
     * Wer alle zwanzig Minuten ausliefert, hat in einem Verlauf über 90 Tage
     * einige tausend Deploys — als senkrechte Striche wäre das eine graue
     * Fläche und keine Auskunft. Abgeschnitten wird bei den **jüngsten**: die
     * Frage „hängt das mit dem Deploy zusammen?" wird über das gestellt, was
     * gerade passiert ist.
     */
    public const LIMIT = 60;

    /**
     * Die Markierungen für ein Raster aus Fenstern (UTC, aufsteigend).
     *
     * Eine Abfrage für die ganze Seite, nicht eine je Zeile: alle Grafiken
     * einer Fehlerliste stehen über demselben Raster und zeigen deshalb
     * dieselben Striche.
     *
     * @param  list<int>  $projectIds
     * @param  list<string>  $windows
     * @return list<array{slot: int, version: string, environment: string, atLabel: string, label: string}>
     */
    public static function forWindows(array $projectIds, array $windows, CountPeriod $period, ?string $environment = null): array
    {
        if ($projectIds === [] || $windows === []) {
            return [];
        }

        $index = array_flip($windows);

        $from = CarbonImmutable::parse($windows[0], 'UTC');

        // Das Ende ist das **Ende** des letzten Fensters und nicht sein Anfang:
        // ein Deploy um 14:50 gehört in das Fenster 14:00, und mit `<= 14:00`
        // fiele er heraus — die Markierung fehlte ausgerechnet für die
        // Auslieferung von gerade eben.
        $last = CarbonImmutable::parse($windows[array_key_last($windows)], 'UTC');
        $to = $period === CountPeriod::Hour ? $last->addHour() : $last->addDay();

        $rows = Deploy::query()
            ->join('environments', 'environments.id', '=', 'deploys.environment_id')
            ->join('releases', 'releases.id', '=', 'deploys.release_id')
            ->join('projects', 'projects.id', '=', 'deploys.project_id')
            ->whereIn('deploys.project_id', $projectIds)
            ->where('deploys.finished_at', '>=', $from)
            ->where('deploys.finished_at', '<', $to)
            ->when(
                $environment !== null,
                static fn ($query) => $query->where('environments.name', $environment),
                // Ohne Auswahl die Standard-Umgebung — je Projekt ihre eigene,
                // deshalb ein Spaltenvergleich und keine feste Zeichenkette.
                static fn ($query) => $query->whereColumn('environments.name', 'projects.default_environment'),
            )
            ->orderByDesc('deploys.finished_at')
            ->orderByDesc('deploys.id')
            ->limit(self::LIMIT)
            // Rohe Zeilen und keine Modelle: gebraucht werden drei Spalten aus
            // drei Tabellen, und ein Deploy-Modell mit angehefteter Version
            // wäre ein halb gefüllter Gegenstand, den niemand weiterreichen
            // darf.
            ->toBase()
            ->get([
                'deploys.finished_at',
                'releases.version',
                'environments.name as environment_name',
            ]);

        $markers = [];

        foreach ($rows as $row) {
            /** @var object{finished_at: string, version: string, environment_name: string} $row */
            $at = CarbonImmutable::parse($row->finished_at);
            $slot = $index[$period->windowFor($at)->format('Y-m-d H:i:s')] ?? null;

            if ($slot === null) {
                continue;
            }

            $markers[] = [
                'slot' => $slot,
                'version' => (string) $row->version,
                'environment' => (string) $row->environment_name,
                'atLabel' => Formats::dateTime($at),
                // Fertig beschriftet und nicht in der Oberfläche zusammengesetzt:
                // die Sprache kennt der Server, und der Strich in der Grafik
                // wäre ohne Beschriftung ein Strich ohne Auskunft.
                'label' => __('releases.deploys.marker', [
                    'version' => (string) $row->version,
                    'environment' => (string) $row->environment_name,
                    'at' => Formats::dateTime($at),
                ]),
            ];
        }

        // Wieder aufsteigend: gelesen wird die Grafik von links, und die
        // absteigende Ordnung oben diente nur dem Abschneiden.
        usort($markers, static fn (array $a, array $b): int => $a['slot'] <=> $b['slot']);

        return $markers;
    }

    /**
     * Dasselbe für einen Verlauf, dessen Punkte **nicht** lückenlos sind.
     *
     * Die Antwortzeiten-Grafik (PF3) zeichnet nur Fenster, für die es
     * Messungen gibt; ihre Stellen ergeben sich also aus den Punkten und nicht
     * aus dem Zeitraum. Übergeben werden deshalb genau deren Fenster — als
     * ISO-8601, wie sie in der Nutzlast stehen —, und `slot` ist die Nummer
     * darin. Eine Auslieferung in einem Fenster ohne Messung bekommt keine
     * Markierung: es gibt keine Stelle, an der sie stehen könnte.
     *
     * @param  list<int>  $projectIds
     * @param  list<string>  $points
     * @return list<array{slot: int, version: string, environment: string, atLabel: string, label: string}>
     */
    public static function forPoints(array $projectIds, array $points, CountPeriod $period, ?string $environment = null): array
    {
        $windows = array_map(
            static fn (string $point): string => CarbonImmutable::parse($point)->utc()->format('Y-m-d H:i:s'),
            $points,
        );

        return self::forWindows($projectIds, $windows, $period, $environment);
    }

    /**
     * Dasselbe für eine Fehlerliste: Raster und Umgebung stammen aus der
     * Filterleiste.
     *
     * @return list<array{slot: int, version: string, environment: string, atLabel: string, label: string}>
     */
    public static function forFilter(GlobalFilter $filter): array
    {
        return self::forWindows(
            $filter->projectIds(),
            IssueSeries::windowsFor($filter),
            IssueSeries::periodFor($filter),
            $filter->environment,
        );
    }
}
