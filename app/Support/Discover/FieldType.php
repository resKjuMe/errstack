<?php

namespace App\Support\Discover;

use App\Support\Performance\DurationHistogram;

/**
 * Was für eine Art Wert in einem Feld steht.
 *
 * Die Art entscheidet über zwei Dinge, die sonst geraten werden müssten: welche
 * Vergleiche in der Suche etwas bedeuten (`>` bei einer Zahl, „an diesem Tag" bei
 * einem Datum) und welche Rechenarten überhaupt anwendbar sind — ein Mittelwert
 * über Browsernamen ist keine Zahl, sondern ein Missverständnis.
 *
 * **Dauer ist ein eigener Typ und nicht einfach eine Zahl.** Nur über Dauern gibt
 * es die logarithmische Verteilung, aus der sich Perzentile lesen lassen
 * ({@see DurationHistogram}); die Klassen sind auf
 * Mikrosekunden geeicht. Ein `p95(span_count)` über dieselbe Verteilung wäre eine
 * Zahl mit einer Genauigkeit, die niemand behaupten wollte.
 */
enum FieldType: string
{
    case Text = 'text';

    case Number = 'number';

    /** Eine Dauer in Mikrosekunden. */
    case Duration = 'duration';

    case Timestamp = 'timestamp';

    public function isNumeric(): bool
    {
        return $this === self::Number || $this === self::Duration;
    }
}
