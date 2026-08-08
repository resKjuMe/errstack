<?php

namespace App\Enums;

use App\Models\TransactionTrendDetection;
use Illuminate\Database\Eloquent\Builder;

/**
 * Die Sortierung der Trend-Liste.
 *
 * Zwei Angebote, weil es zwei Arten gibt, diese Liste zu lesen. „Was ist am
 * schlimmsten" ist die Frage beim Aufschlagen — deshalb die Vorgabe. „Was ist
 * zuletzt umgeschlagen" ist die Frage nach einer Auslieferung, und dafür zählt
 * der Zeitpunkt und nicht der Umfang.
 *
 * Eine eigene Aufzählung neben {@see PerformanceIssueSort} und {@see IssueSort}
 * und aus demselben Grund wie die beiden untereinander: es sind andere Spalten
 * und eine andere erste Frage. Eine gemeinsame mit Sonderfällen für „hier gilt
 * eine andere Vorgabe" wäre schwerer zu lesen als drei kurze.
 */
enum TrendListSort: string
{
    /** Verschlechterungen zuerst, darin die größte Änderung. */
    case Impact = 'impact';

    /** Was zuletzt umgeschlagen ist. */
    case Recent = 'recent';

    public static function default(): self
    {
        return self::Impact;
    }

    public function label(): string
    {
        return __('performance_trends.sort.'.$this->value);
    }

    /**
     * @param  Builder<TransactionTrendDetection>  $query
     */
    public function apply(Builder $query): void
    {
        match ($this) {
            // Zwei Kriterien und nicht eines: nach dem Betrag allein stünde eine
            // Halbierung der Antwortzeit über einer Verdopplung, und die gute
            // Nachricht verdrängte die schlechte vom oberen Rand der Liste.
            self::Impact => $query
                ->orderByRaw("case when direction = 'worse' then 0 else 1 end")
                ->orderByRaw('abs(change_ratio) desc'),
            self::Recent => $query->orderByDesc('breakpoint_at'),
        };

        // Die Kennung als letztes Kriterium: zwei Feststellungen mit derselben
        // Änderung stünden sonst bei jedem Aufruf in anderer Reihenfolge, und
        // eine Liste, die beim Blättern die Zeilen tauscht, zeigt manche zweimal
        // und manche nie.
        $query->orderByDesc('id');
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $sort): array => ['value' => $sort->value, 'label' => $sort->label()],
            self::cases(),
        );
    }
}
