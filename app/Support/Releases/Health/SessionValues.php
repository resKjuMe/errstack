<?php

namespace App\Support\Releases\Health;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Das Lesen der Werte, die in beiden Sitzungs-Formaten gleich aussehen.
 *
 * Einzelne und gebündelte Sitzungen kommen in verschiedenen Elementen an, aber
 * eine Zeitangabe ist in beiden dieselbe Zeitangabe und eine Anzahl dieselbe
 * Anzahl. Zweimal gedeutet liefe es auseinander — und zwar an der
 * unangenehmsten Stelle: eine Zeitangabe, die einmal in UTC und einmal in der
 * Serverzeit gelesen wird, schiebt gebündelte und einzelne Sitzungen in
 * verschiedene Zeitfenster.
 */
final class SessionValues
{
    /**
     * Eine Zeitangabe des SDK, in UTC. `null`, wenn sie sich nicht lesen lässt.
     *
     * Zahlen sind Unix-Zeitstempel — so schicken es einige SDKs —, Zeichenketten
     * werden gedeutet. Eine unlesbare Angabe lässt die Aufnahme nicht scheitern:
     * die Sitzung wird verworfen, die übrigen Elemente des Envelope kommen an.
     */
    public static function time(mixed $value): ?CarbonImmutable
    {
        if (is_int($value) || is_float($value)) {
            return CarbonImmutable::createFromTimestampUTC((int) $value);
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Eine gemeldete Anzahl. Negatives und Unsinniges wird zu null — eine
     * Sitzung mit „minus drei Fehlern" gibt es nicht, und eine erfundene Zahl
     * wäre schlimmer als eine fehlende.
     */
    public static function count(mixed $value): int
    {
        if (is_int($value)) {
            return max(0, $value);
        }

        if (is_float($value) || (is_string($value) && preg_match('/^\d+$/', $value) === 1)) {
            return max(0, (int) $value);
        }

        return 0;
    }

    /**
     * Die Nutzerkennung (`did`), roh. Gehasht wird sie erst beim Ablegen — hier
     * steht nur, ob überhaupt eine dabei war.
     */
    public static function identifier(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
