<?php

namespace App\Enums;

/**
 * Wie dringend ein Fehler-Eintrag ist.
 *
 * Getrennt vom Schweregrad der Meldung ({@see EventLevel}), und das ist keine
 * Doppelung: der Grad kommt vom SDK und beschreibt **die Meldung** — eine
 * `fatal`-Meldung, die einmal im Quartal einen Testlauf trifft, ist nicht
 * dringend. Die Dringlichkeit beschreibt **den Eintrag** und ist eine
 * Entscheidung der Leute, die die Liste ansehen.
 *
 * Drei Stufen, weil eine vierte in der Praxis nicht gepflegt wird: was
 * niemand einordnen kann, bleibt auf dem Vorgabewert stehen, und eine Skala,
 * die zur Hälfte aus Vorgabewerten besteht, sortiert nichts mehr.
 */
enum IssuePriority: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    /**
     * Die Stufe, mit der ein Eintrag entsteht.
     *
     * Die Mitte und nicht „hoch": ein neuer Eintrag ist unbewertet, und alles
     * als dringend anzulegen macht die Dringlichkeit wertlos.
     */
    public const DEFAULT = self::Medium;

    public function label(): string
    {
        return __('enums.issue_priority.'.$this->value);
    }

    /**
     * Die Ordnung für die Sortierung — höhere Zahl heißt dringender.
     *
     * Nötig, weil die gespeicherten Werte Wörter sind und `order by priority`
     * sie alphabetisch sortieren würde: „high", „low", „medium".
     */
    public function rank(): int
    {
        return match ($this) {
            self::High => 3,
            self::Medium => 2,
            self::Low => 1,
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $priority): array => ['value' => $priority->value, 'label' => $priority->label()],
            self::cases(),
        );
    }
}
