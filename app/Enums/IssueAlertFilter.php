<?php

namespace App\Enums;

/**
 * Die Einschränkung einer Alarm-Regel: **worauf** die Bedingung zutreffen muss.
 *
 * Filter entscheiden nichts über den Anlass, sie nehmen nur weg. Genau deshalb
 * werden sie **nach** den Bedingungen geprüft ({@see IssueAlertCondition}): der
 * teurere Teil soll nur die Fälle sehen, die es überhaupt bis dorthin schaffen.
 *
 * Die Zuständigkeit fehlt in dieser Aufzählung. Sie steht in der Aufgabe, hängt
 * aber an S7 — solange es keine Zuweisung gibt, wäre ein Filter darauf ein
 * Formularfeld ohne Wirkung.
 */
enum IssueAlertFilter: string
{
    /** Der Grad des Ereignisses (fatal, error, warning, info, debug). */
    case Level = 'level';

    /** Das Alter des Fehler-Eintrags, gerechnet ab seinem ersten Auftreten. */
    case Age = 'age';

    /** Wie oft der Eintrag insgesamt schon gesehen wurde. */
    case TimesSeen = 'times_seen';

    /** Ein Merkmal des Ereignisses (Browser, Server, eigene Merkmale). */
    case Tag = 'tag';

    /** Die Fassung, in der das Ereignis auftrat. */
    case Release = 'release';

    /** Die Umgebung, in der das Ereignis auftrat. */
    case Environment = 'environment';

    /**
     * Die Vergleiche, die dieser Filter zulässt.
     *
     * @return list<IssueAlertComparison>
     */
    public function comparisons(): array
    {
        return match ($this) {
            self::Level, self::TimesSeen => [
                IssueAlertComparison::Equals,
                IssueAlertComparison::AtLeast,
                IssueAlertComparison::AtMost,
            ],
            self::Age => [
                IssueAlertComparison::OlderThan,
                IssueAlertComparison::NewerThan,
            ],
            default => [
                IssueAlertComparison::Equals,
                IssueAlertComparison::NotEquals,
                IssueAlertComparison::Contains,
            ],
        };
    }

    /** Braucht dieser Filter einen Merkmalsnamen (nur das Merkmal selbst)? */
    public function hasKey(): bool
    {
        return $this === self::Tag;
    }

    /** Ist der Wert eine Zahl statt eines Textes? */
    public function isNumeric(): bool
    {
        return $this === self::Age || $this === self::TimesSeen;
    }

    public function label(): string
    {
        return __('enums.issue_alert_filter.'.$this->value);
    }

    /**
     * @return list<array{value: string, label: string, hasKey: bool, numeric: bool, comparisons: list<string>}>
     */
    public static function options(): array
    {
        return array_map(static fn (self $filter): array => [
            'value' => $filter->value,
            'label' => $filter->label(),
            'hasKey' => $filter->hasKey(),
            'numeric' => $filter->isNumeric(),
            'comparisons' => array_map(
                static fn (IssueAlertComparison $comparison): string => $comparison->value,
                $filter->comparisons(),
            ),
        ], self::cases());
    }
}
