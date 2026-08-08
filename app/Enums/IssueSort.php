<?php

namespace App\Enums;

use App\Models\Issue;
use Illuminate\Database\Eloquent\Builder;

/**
 * Die Sortierung der Fehlerliste.
 *
 * Eine Aufzählung und nicht ein durchgereichter Spaltenname: der Wert steht in
 * der Adresszeile und käme damit von außen. „Sortiere nach `password`" wäre
 * sonst eine gültige Anfrage — hier ist nur sortierbar, was hier steht.
 *
 * Jede Sortierung endet auf `id`, und das ist kein Schmuck: `last_seen` ist bei
 * einer Fehlerflut für Dutzende Einträge derselbe Zeitstempel. Ohne einen
 * eindeutigen letzten Schlüssel wäre die Reihenfolge innerhalb einer Sekunde
 * dem Zufall überlassen — und beim Blättern erschiene derselbe Eintrag auf zwei
 * Seiten, während ein anderer auf keiner steht.
 */
enum IssueSort: string
{
    /** Zuletzt aufgetretene zuerst — die Ansicht, mit der die Liste aufgeht. */
    case LastSeen = 'last_seen';

    /** Zuerst aufgetretene zuerst: „was ist neu?" */
    case FirstSeen = 'first_seen';

    /** Die Häufigsten zuerst. */
    case TimesSeen = 'times_seen';

    /** Die Dringlichsten zuerst. */
    case Priority = 'priority';

    public static function default(): self
    {
        return self::LastSeen;
    }

    public function label(): string
    {
        return __('issues.sort.'.$this->value);
    }

    /**
     * Legt die Sortierung auf eine Abfrage.
     *
     * Die Dringlichkeit ist der Sonderfall: gespeichert sind Wörter, und
     * `order by priority` sortierte sie alphabetisch — „high", „low", „medium".
     * Sortiert wird deshalb über die Ordnung aus {@see IssuePriority::rank()},
     * ausgeschrieben als `case`. Die Werte kommen dabei aus der Aufzählung und
     * nicht aus der Anfrage; in der Anweisung steht nichts, was ein Aufrufer
     * beeinflussen könnte.
     *
     * @param  Builder<Issue>  $query
     */
    public function apply(Builder $query): void
    {
        match ($this) {
            self::LastSeen => $query->orderByDesc('last_seen'),
            self::FirstSeen => $query->orderByDesc('first_seen'),
            self::TimesSeen => $query->orderByDesc('times_seen'),
            // Bei gleicher Dringlichkeit entscheidet die Zeit: eine Liste, in
            // der alle „Mittel" sind, wäre sonst unsortiert.
            self::Priority => $query
                ->orderByRaw(self::priorityOrder())
                ->orderByDesc('last_seen'),
        };

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

    private static function priorityOrder(): string
    {
        $cases = '';

        foreach (IssuePriority::cases() as $priority) {
            $cases .= sprintf(" when '%s' then %d", $priority->value, $priority->rank());
        }

        return 'case priority'.$cases.' else 0 end desc';
    }
}
