<?php

namespace App\Enums;

use App\Models\Issue;
use Illuminate\Database\Eloquent\Builder;

/**
 * Die Sortierung der Leistungsprobleme.
 *
 * Eine eigene Aufzählung neben {@see IssueSort}, weil die erste Frage eine
 * andere ist. Bei Fehlern ist es „was ist zuletzt passiert" — ein Fehler von
 * gestern ist erledigt, einer von eben brennt. Ein Leistungsproblem brennt nie;
 * es kostet, und zwar dauernd. Die Frage lautet deshalb „was kostet am meisten
 * Zeit", und die Vorgabe ist entsprechend die verlorene Zeit.
 *
 * Der Rest ist dieselbe Liste wie bei den Fehlern — dieselbe Tabelle, dieselben
 * Spalten. Nur die Reihenfolge der Angebote ist eine andere, und das genügt als
 * Grund für eine eigene Aufzählung: eine gemeinsame mit einem Sonderfall für
 * „hier gilt eine andere Vorgabe" wäre schwerer zu lesen als zwei kurze.
 */
enum PerformanceIssueSort: string
{
    /** Was insgesamt die meiste Zeit kostet. */
    case TimeLost = 'time_lost';

    /** Was am häufigsten auftritt. */
    case TimesSeen = 'times_seen';

    /** Was zuletzt aufgetreten ist. */
    case LastSeen = 'last_seen';

    /** Was zuerst aufgetreten ist. */
    case FirstSeen = 'first_seen';

    public static function default(): self
    {
        return self::TimeLost;
    }

    public function label(): string
    {
        return __('performance_issues.sort.'.$this->value);
    }

    /**
     * @param  Builder<Issue>  $query
     */
    public function apply(Builder $query): void
    {
        match ($this) {
            self::TimeLost => $query->orderByDesc('time_lost_us'),
            self::TimesSeen => $query->orderByDesc('times_seen'),
            self::LastSeen => $query->orderByDesc('last_seen'),
            self::FirstSeen => $query->orderByDesc('first_seen'),
        };

        // Die Kennung als letztes Kriterium: zwei Einträge mit derselben
        // verlorenen Zeit stünden sonst bei jedem Aufruf in anderer
        // Reihenfolge, und eine Liste, die beim Blättern die Zeilen tauscht,
        // zeigt manche zweimal und manche nie.
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
