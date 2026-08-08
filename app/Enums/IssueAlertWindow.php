<?php

namespace App\Enums;

/**
 * Die Einheit, in der das Zeitfenster einer Bedingung angegeben wird.
 *
 * Zwei Einheiten und nicht eine, weil die Bedingungen aus zwei verschiedenen
 * Quellen zählen: der Ereignisstrom gibt Minuten her, die vorberechneten Zähler
 * (App\Models\IssueCount) nur Stunden. Eine gemeinsame Einheit hieße, eine der
 * beiden Angaben zu erfinden.
 */
enum IssueAlertWindow: string
{
    case Minutes = 'minutes';

    case Hours = 'hours';

    public function toMinutes(int $value): int
    {
        return match ($this) {
            self::Minutes => $value,
            self::Hours => $value * 60,
        };
    }

    /**
     * Die größte zulässige Spanne.
     *
     * Ein Tag am Ereignisstrom: darüber hinaus wäre die Zählung ein Durchlauf
     * über Meldungen, die die Aufbewahrung ohnehin bald wegräumt. Vier Wochen
     * beim Vorwochen-Vergleich, weil dessen Grundlage die Tageszähler sind.
     */
    public function max(): int
    {
        return match ($this) {
            self::Minutes => 1440,
            self::Hours => 672,
        };
    }
}
