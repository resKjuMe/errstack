<?php

namespace App\Enums;

use App\Support\Discover\DiscoverEngine;

/**
 * Die Darstellungsart einer Dashboard-Kachel.
 *
 * **Die Art entscheidet, welche Frage der Motor bekommt** — nicht bloß, wie das
 * Ergebnis aussieht. Linie, Fläche und Balken zeigen einen *Verlauf* und
 * brauchen deshalb eine Zeitreihe ({@see DiscoverEngine::series()}); Tabelle,
 * große Zahl und Weltkarte zeigen einen *Stand* und brauchen die Tabelle. Beides
 * aus derselben Abfrage zu holen und die Hälfte wegzuwerfen, wäre die doppelte
 * Arbeit auf einem Bildschirm mit zwanzig Kacheln.
 *
 * **Die große Zahl und die Weltkarte sind keine Sonderfälle der Tabelle**,
 * sondern Tabellen mit einer Erwartung an ihre Form: die große Zahl zeigt die
 * erste Kennzahl der ersten Zeile, die Weltkarte erwartet ein Länderkürzel als
 * Gruppierung. Wo die Erwartung nicht erfüllt ist, sagt die Kachel das — sie
 * zeigt nicht ersatzweise etwas anderes.
 */
enum WidgetType: string
{
    /** Verlauf als Linie — der Regelfall für „wie hat sich das entwickelt". */
    case Line = 'line';

    /** Derselbe Verlauf mit gefüllter Fläche — für Mengen, die sich stapeln. */
    case Area = 'area';

    /** Verlauf als Balken — für gezählte Ereignisse je Zeitabschnitt. */
    case Bar = 'bar';

    /** Die Rangliste als Tabelle. */
    case Table = 'table';

    /** Eine einzige Zahl, groß — die Kennzahl, die man im Vorbeigehen liest. */
    case BigNumber = 'big_number';

    /** Die Verteilung über Länder. */
    case WorldMap = 'world_map';

    /**
     * Braucht diese Art einen Verlauf statt einer Rangliste?
     */
    public function isSeries(): bool
    {
        return match ($this) {
            self::Line, self::Area, self::Bar => true,
            default => false,
        };
    }

    /**
     * Zeigt die Kachel genau eine Zahl?
     *
     * Dann wird die Abfrage auf eine Zeile eingekürzt: eine große Zahl über
     * fünfzig gelesenen Zeilen wäre neunundvierzig Zeilen Arbeit für nichts.
     */
    public function isSingleValue(): bool
    {
        return $this === self::BigNumber;
    }

    /**
     * Das Feld, nach dem eine Weltkarte gruppiert sein muss — sonst gibt es
     * nichts einzufärben.
     */
    public const COUNTRY_FIELD = 'geo.country';

    public function label(): string
    {
        return __('enums.widget_type.'.$this->value);
    }

    /**
     * @return list<array{value: string, label: string, series: bool}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $type): array => [
                'value' => $type->value,
                'label' => $type->label(),
                'series' => $type->isSeries(),
            ],
            self::cases(),
        );
    }
}
