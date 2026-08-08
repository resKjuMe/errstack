<?php

namespace App\Support\Performance\Vitals;

use App\Enums\VitalRating;
use App\Enums\WebVital;
use App\Models\Transaction;

/**
 * Ein einzelner gemessener Web Vital: welcher, wie groß, wie zu bewerten.
 *
 * Hier steht die Umrechnung in die gemeinsame Einheit — und das ist der Grund,
 * warum es die Klasse gibt. Ein SDK darf jeden Messwert in jeder Einheit
 * melden: dasselbe LCP kommt als `{"value": 2500, "unit": "millisecond"}`, als
 * `{"value": 2.5, "unit": "second"}` oder ganz ohne Einheit an. Würde jede
 * Auswertung das selbst deuten, stünden in derselben Verteilung Sekunden neben
 * Millisekunden — und die Zahl, die dabei herauskommt, sähe aus wie eine
 * Messung.
 *
 * Was sich nicht deuten lässt, wird **verworfen** und nicht geschätzt
 * ({@see fromMeasurement()}). Ein Messwert ohne erkennbare Einheit ist keine
 * Auskunft, sondern eine Zahl; sie mitzuzählen hieße, ein Perzentil aus
 * Vermutungen zu bilden.
 */
final class VitalReading
{
    /**
     * @param  int  $value  In Millionsteln ({@see WebVital}).
     */
    private function __construct(
        public readonly WebVital $vital,
        public readonly int $value,
    ) {}

    /**
     * Liest einen Eintrag aus {@see Transaction::$measurements}.
     *
     * `null` heißt: kein verwertbarer Web Vital. Das trifft drei Fälle — der
     * Name gehört zu keinem bewerteten Messwert, der Wert ist keine Zahl, oder
     * die Einheit passt nicht zur Art des Messwerts.
     *
     * @param  string  $name  Der Schlüssel, unter dem der Wert gemeldet wurde.
     * @param  mixed  $entry  Der Eintrag, wie die Aufnahme ihn abgelegt hat.
     */
    public static function fromMeasurement(string $name, mixed $entry): ?self
    {
        $vital = WebVital::fromMeasurement($name);

        if ($vital === null || ! is_array($entry)) {
            return null;
        }

        $raw = $entry['value'] ?? null;

        if (! is_int($raw) && ! is_float($raw)) {
            return null;
        }

        $raw = (float) $raw;

        if (! is_finite($raw) || $raw < 0) {
            // Negative Werte gibt es nicht: weder eine Dauer noch eine
            // Verschiebung kann kleiner als null sein. Solche Meldungen kommen
            // von falsch gestellten Uhren, und ein negatives LCP würde jedes
            // Perzentil des Zeitfensters nach unten ziehen.
            return null;
        }

        $unit = $entry['unit'] ?? null;
        $unit = is_string($unit) ? strtolower(trim($unit)) : null;

        $value = $vital->isScore()
            ? self::score($raw, $unit)
            : self::duration($raw, $unit);

        return $value === null ? null : new self($vital, $value);
    }

    /**
     * Alle verwertbaren Web Vitals einer Messung.
     *
     * Je Messwert höchstens einer: meldet ein SDK denselben zweimal (etwa als
     * `lcp` und `measurements.lcp`), zählt der erste. Zwei Zeilen für dasselbe
     * LCP eines Ladevorgangs wären eine verdoppelte Häufigkeit in der
     * Verteilung.
     *
     * @param  array<string, mixed>|null  $measurements
     * @return array<string, self> Messwert-Schlüssel → Messung.
     */
    public static function all(?array $measurements): array
    {
        if ($measurements === null) {
            return [];
        }

        $readings = [];

        foreach ($measurements as $name => $entry) {
            $reading = self::fromMeasurement((string) $name, $entry);

            if ($reading === null || isset($readings[$reading->vital->value])) {
                continue;
            }

            $readings[$reading->vital->value] = $reading;
        }

        return $readings;
    }

    public function rating(): VitalRating
    {
        return $this->vital->rate($this->value);
    }

    /**
     * Eine Dauer in Mikrosekunden.
     *
     * Ohne Angabe gilt die Millisekunde. Das ist keine Notlösung, sondern die
     * Vorgabe des Schemas: die Browser-Messwerte werden dort in Millisekunden
     * geführt, und die SDKs lassen die Einheit deshalb regelmäßig weg.
     *
     * Eine Einheit, die keine Zeit ist (`byte`, `ratio`), führt zu `null`: ein
     * LCP in Bytes ist eine Fehlmeldung, und sie stillschweigend als
     * Millisekunden zu lesen wäre eine erfundene Zahl.
     */
    private static function duration(float $value, ?string $unit): ?int
    {
        $factor = match ($unit) {
            null, '', 'millisecond', 'milliseconds' => 1_000.0,
            'nanosecond', 'nanoseconds' => 0.001,
            'microsecond', 'microseconds' => 1.0,
            'second', 'seconds' => 1_000_000.0,
            'minute', 'minutes' => 60_000_000.0,
            'hour', 'hours' => 3_600_000_000.0,
            default => null,
        };

        return $factor === null ? null : (int) round($value * $factor);
    }

    /**
     * Eine Punktzahl in Millionsteln.
     *
     * Der Verschiebungswert hat keine Einheit; die SDKs schicken deshalb einen
     * leeren Text, `none` oder `ratio`. Eine Zeiteinheit an dieser Stelle ist
     * eine Fehlmeldung — ein CLS „in Millisekunden" gibt es nicht.
     */
    private static function score(float $value, ?string $unit): ?int
    {
        return match ($unit) {
            null, '', 'none', 'ratio', 'unit' => (int) round($value * 1_000_000),
            // Ein in Prozent gemeldeter Anteil ist eindeutig gemeint und lässt
            // sich umrechnen — im Gegensatz zu einer Zeiteinheit.
            'percent' => (int) round($value * 10_000),
            default => null,
        };
    }
}
