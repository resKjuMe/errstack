<?php

namespace App\Enums;

use App\Support\Performance\TransactionTrend;

/**
 * Wohin sich eine Transaktion gegenüber dem Vorzeitraum bewegt hat.
 *
 * Fünf Fälle und nicht drei, weil „kein Pfeil" zwei ganz verschiedene Gründe
 * haben kann: es gab die Transaktion vorher nicht, oder es gab sie, aber zu
 * selten, um aus dem Unterschied etwas zu lesen. Beides als „unverändert"
 * auszugeben wäre eine Aussage, die die Daten nicht hergeben.
 *
 * Gerechnet wird der Fall in {@see TransactionTrend}; hier steht nur, wie er
 * heißt.
 */
enum TrendDirection: string
{
    /** Im Vorzeitraum nicht gemessen — die Transaktion ist neu. */
    case New = 'new';

    /** Zu wenige Messungen auf einer der beiden Seiten. */
    case Unknown = 'unknown';

    /** Innerhalb des Bandes, in dem eine Änderung kein Signal ist. */
    case Flat = 'flat';

    case Better = 'better';

    case Worse = 'worse';

    public function label(): string
    {
        return __('enums.trend_direction.'.$this->value);
    }
}
