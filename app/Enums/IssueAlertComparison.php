<?php

namespace App\Enums;

/**
 * Wie ein Filter seinen Wert vergleicht.
 *
 * `AtLeast`/`AtMost` sind beim Grad ausdrücklich als **Schwere** zu lesen und
 * nicht als Alphabet: „mindestens error" schließt `fatal` ein, obwohl `fatal`
 * davor käme. Die Ordnung dafür liefert {@see EventLevel::severity()}.
 */
enum IssueAlertComparison: string
{
    case Equals = 'eq';

    case NotEquals = 'ne';

    case Contains = 'contains';

    case AtLeast = 'gte';

    case AtMost = 'lte';

    case OlderThan = 'older';

    case NewerThan = 'newer';

    public function label(): string
    {
        return __('enums.issue_alert_comparison.'.$this->value);
    }

    /**
     * Der Vergleich zweier Texte.
     *
     * Ohne Rücksicht auf Groß- und Kleinschreibung: Umgebungen und Fassungen
     * kommen aus den SDKs, und „Production" und „production" sind dieselbe
     * Umgebung — eine Regel, die daran scheitert, sieht aus wie eine kaputte.
     */
    public function matchesText(?string $actual, string $expected): bool
    {
        $actual ??= '';

        return match ($this) {
            self::Equals => mb_strtolower($actual) === mb_strtolower($expected),
            self::NotEquals => mb_strtolower($actual) !== mb_strtolower($expected),
            self::Contains => $expected === '' || mb_stripos($actual, $expected) !== false,
            default => false,
        };
    }

    public function matchesNumber(float $actual, float $expected): bool
    {
        return match ($this) {
            self::Equals => $actual === $expected,
            self::NotEquals => $actual !== $expected,
            self::AtLeast, self::OlderThan => $actual >= $expected,
            self::AtMost, self::NewerThan => $actual <= $expected,
            default => false,
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(static fn (self $comparison): array => [
            'value' => $comparison->value,
            'label' => $comparison->label(),
        ], self::cases());
    }
}
