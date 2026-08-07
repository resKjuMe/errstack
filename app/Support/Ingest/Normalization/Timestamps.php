<?php

namespace App\Support\Ingest\Normalization;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Bringt die Zeitangaben der SDKs auf eine Form.
 *
 * Sentry lässt zwei Schreibweisen zu, und die SDKs nutzen beide: eine
 * Fließkommazahl mit Sekunden seit 1970 (mit Bruchteilen) und eine
 * Zeichenkette nach ISO 8601. Dieselbe Meldung, zweimal geschickt, kann so
 * zwei verschiedene Formen tragen — und ohne Vereinheitlichung stünden beide
 * unvergleichbar nebeneinander in der Zeitleiste.
 *
 * Die zweite Aufgabe ist die unbequemere: den Uhren der überwachten
 * Anwendungen ist nicht zu trauen. Ein Server mit falsch gestellter Uhr
 * schickt Meldungen aus dem Jahr 2035, und die stehen danach für immer oben in
 * jeder nach Zeit sortierten Liste. Deshalb wird nicht nur gelesen, sondern
 * auch auf Plausibilität geprüft.
 */
final class Timestamps
{
    /**
     * Wie weit eine Meldung in der Zukunft liegen darf, bevor sie als
     * Uhrenfehler gilt. Eine Stunde deckt eine falsch eingestellte Zeitzone ab,
     * ohne ein Jahr 2035 durchzulassen.
     */
    private const MAX_FUTURE_SECONDS = 3_600;

    /**
     * Wie weit eine Meldung zurückliegen darf. Ein Jahr ist großzügig genug
     * für ein SDK, das nach längerer Netztrennung seine Warteschlange leert,
     * und eng genug, um die Werte auszusortieren, die nahe 1970 liegen —
     * gewöhnlich ein Feld, das versehentlich in Millisekunden statt Sekunden
     * gefüllt wurde und deshalb durch 1000 zu klein ist.
     */
    private const MAX_PAST_SECONDS = 365 * 24 * 3_600;

    /**
     * Der Zeitpunkt einer Meldung, notfalls der Zeitpunkt ihrer Annahme.
     *
     * Der Vorgabewert ist nicht beliebig gewählt: eine Meldung ohne Zeitpunkt
     * ist trotzdem gerade eingetroffen, und `null` in einer Zeitleiste wäre
     * eine Meldung, die es nirgends anzuzeigen gäbe.
     */
    public function required(mixed $value, string $path, Notes $notes, Carbon $fallback): Carbon
    {
        return $this->optional($value, $path, $notes) ?? $fallback;
    }

    /**
     * Ein Zeitpunkt, der auch fehlen darf — der einer Spur etwa.
     */
    public function optional(mixed $value, string $path, Notes $notes): ?Carbon
    {
        $parsed = $this->parse($value);

        if ($parsed === null) {
            if ($value !== null) {
                $notes->invalid($path);
            }

            return null;
        }

        $now = Carbon::now();

        if ($parsed->greaterThan($now->copy()->addSeconds(self::MAX_FUTURE_SECONDS))) {
            $notes->invalid($path);

            return $now;
        }

        if ($parsed->lessThan($now->copy()->subSeconds(self::MAX_PAST_SECONDS))) {
            $notes->invalid($path);

            return null;
        }

        return $parsed;
    }

    /**
     * Liest die Angabe, ohne über ihre Plausibilität zu urteilen.
     */
    private function parse(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy();
        }

        if (is_int($value) || is_float($value)) {
            if (is_float($value) && ! is_finite($value)) {
                return null;
            }

            return Carbon::createFromTimestampMs((int) round((float) $value * 1_000));
        }

        if (! is_string($value)) {
            return null;
        }

        $text = trim($value);

        if ($text === '') {
            return null;
        }

        // Auch die Sekunden-Schreibweise kommt als Zeichenkette vor — dann ist
        // sie eine Zahl und keine Datumsangabe, und `Carbon::parse()` würde
        // „1754500000" als Uhrzeit zu lesen versuchen.
        if (preg_match('/^\d{9,}(\.\d+)?$/', $text) === 1) {
            return Carbon::createFromTimestampMs((int) round(((float) $text) * 1_000));
        }

        try {
            return Carbon::parse($text);
        } catch (Throwable) {
            // `Carbon::parse()` wirft bei allem, was nicht als Datum lesbar
            // ist. Das ist hier kein Fehler der Verarbeitung, sondern eine
            // Angabe des SDK, mit der sich nichts anfangen lässt — der
            // Aufrufer vermerkt sie und nimmt den Vorgabewert.
            return null;
        }
    }
}
