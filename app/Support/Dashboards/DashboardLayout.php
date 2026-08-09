<?php

namespace App\Support\Dashboards;

use App\Models\DashboardWidget;
use Illuminate\Support\Collection;

/**
 * Das Raster, in dem die Kacheln liegen — und die Regeln, nach denen eine Lage
 * gültig ist.
 *
 * **Gezählt wird in Rasterfeldern, nicht in Pixeln.** Ein Dashboard soll auf
 * einem schmalen Bildschirm dieselbe *Anordnung* haben und nicht dieselbe
 * *Größe*: die Spaltenbreite ergibt sich im Browser aus der verfügbaren Breite,
 * die Zeilenhöhe ist fest. Gespeicherte Pixel wären die Auflösung dessen, der
 * die Kachel zuletzt geschoben hat.
 *
 * **Der Server prüft die Lage und rückt sie zurecht.** Nicht weil die Oberfläche
 * es falsch machte, sondern weil sie nicht die einzige Quelle ist: Vorlagen
 * legen Kacheln an, ein Duplikat übernimmt sie, und eine Kachel kann über die
 * Schnittstelle kommen. Eine Kachel, die über den rechten Rand hinausragt,
 * verschwindet im Browser — deshalb wird sie hier hineingeschoben statt
 * abgewiesen.
 *
 * **Überlappungen werden nicht aufgelöst.** Das Raster ist keine Physik-Engine:
 * die Oberfläche legt beim Ziehen fest, wohin eine Kachel kommt, und was sie
 * schickt, gilt. Ein serverseitiges Auseinanderschieben würde die Anordnung
 * hinter dem Rücken dessen ändern, der sie gerade gelegt hat.
 */
final class DashboardLayout
{
    /** Spalten des Rasters. Zwölf, weil sich das durch 2, 3, 4 und 6 teilt. */
    public const COLUMNS = 12;

    /** Schmalste Kachel — darunter passt keine Beschriftung mehr daneben. */
    public const MIN_WIDTH = 2;

    /** Niedrigste Kachel: Überschrift und eine Zahl. */
    public const MIN_HEIGHT = 2;

    /** Höchste Kachel — mehr als das ist kein Ausschnitt mehr, sondern eine Seite. */
    public const MAX_HEIGHT = 12;

    /** Voreinstellung einer neuen Kachel: halbe Breite, mittlere Höhe. */
    public const DEFAULT_WIDTH = 6;

    public const DEFAULT_HEIGHT = 4;

    /**
     * Wie viele Kacheln ein Dashboard tragen darf.
     *
     * Eine Grenze und keine Beschränkung: jede Kachel ist eine eigene Abfrage,
     * und zwanzig davon sollen ohne merkliche Verzögerung dastehen. Das Doppelte
     * ist Luft nach oben; wer mehr braucht, braucht ein zweites Dashboard — und
     * die gibt es beliebig viele.
     */
    public const MAX_WIDGETS = 40;

    /**
     * Eine Lage, die im Raster liegt: Breite gekappt, Kachel hineingeschoben.
     *
     * @return array{x: int, y: int, width: int, height: int}
     */
    public static function normalize(int $x, int $y, int $width, int $height): array
    {
        $width = max(self::MIN_WIDTH, min($width, self::COLUMNS));
        $height = max(self::MIN_HEIGHT, min($height, self::MAX_HEIGHT));
        $x = max(0, min($x, self::COLUMNS - $width));

        return ['x' => $x, 'y' => max(0, $y), 'width' => $width, 'height' => $height];
    }

    /**
     * Die Zeile, in der eine neue Kachel landet: unter allem, was schon da ist.
     *
     * Nicht in die erste Lücke: eine neue Kachel soll dort auftauchen, wo man
     * sie sucht — am Ende —, und nicht zwischen zwei bestehenden, wo sie erst
     * beim Scrollen auffällt.
     *
     * @param  Collection<int, DashboardWidget>  $widgets
     */
    public static function nextRow(Collection $widgets): int
    {
        return (int) $widgets
            ->map(static fn (DashboardWidget $widget): int => $widget->y + $widget->height)
            ->max();
    }
}
