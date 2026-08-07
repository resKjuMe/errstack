<?php

namespace App\Support\Performance;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Die Handgriffe, mit denen aus einem gemeldeten Feld-Baum verlässliche Werte
 * werden.
 *
 * Alles hier ist auf einen Fall ausgelegt: die Angaben kommen von einem
 * fremden SDK in einer fremden Version, und keine davon ist zugesichert. Ein
 * fehlendes Feld, ein Zeitstempel als Text statt als Zahl, eine Kennung in
 * Großbuchstaben — nichts davon darf eine Messung kosten, und nichts davon darf
 * ungeprüft in die Datenbank gelangen (eine zu lange Zeichenkette bricht die
 * Einfügung ab und mit ihr die ganze Transaktion).
 *
 * Deshalb geben alle Methoden `null` zurück, wenn sich der Wert nicht deuten
 * lässt. Was ein fehlender Wert bedeutet, entscheidet der Aufrufer — für die
 * eine Angabe ist es ein Ausschlusskriterium, für die andere ein leeres Feld.
 */
final class PayloadReader
{
    /**
     * Ein Zeitpunkt, wie SDKs ihn schicken: als Unix-Zeit mit Bruchteilen
     * (`1700000000.123456`) oder als Text nach ISO 8601.
     *
     * Beide Formen sind im Feld anzutreffen — die Zahl bei den meisten
     * Server-SDKs, der Text bei den älteren. Wer nur eine davon liest, verliert
     * die Hälfte der Absender.
     */
    public static function time(mixed $value): ?CarbonImmutable
    {
        if (is_int($value) || is_float($value)) {
            // Unendlich und NaN kommen aus Rechenfehlern im SDK und ergeben
            // einen Zeitpunkt, den keine Datenbank annimmt.
            if (! is_finite((float) $value) || $value <= 0) {
                return null;
            }

            return CarbonImmutable::createFromTimestamp((float) $value)->utc();
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            // Immer nach UTC: die Anwendung meldet in ihrer Zeitzone, abgelegt
            // wird in einer. Ohne diese Umrechnung stünde derselbe Augenblick je
            // Absender an einer anderen Stelle der Zeitreihe, und ein Ausschlag
            // erschiene mehrfach über den Tag verteilt.
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Eine Kennung aus dem Trace-Zusammenhang: genau `$length` Hex-Zeichen in
     * Kleinschreibung.
     *
     * Vereinheitlicht wie die Nummer einer Fehlermeldung, und aus demselben
     * Grund: dieselbe Kennung in zwei Schreibweisen wäre für die Zuordnung zwei
     * verschiedene, und ein Trace über mehrere Dienste zerfiele in zwei Hälften.
     */
    public static function hex(mixed $value, int $length): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = strtolower(str_replace('-', '', trim($value)));

        return preg_match('/^[0-9a-f]{'.$length.'}$/', $normalized) === 1 ? $normalized : null;
    }

    /**
     * Eine Zeichenkette, auf die Spaltenbreite gekürzt.
     *
     * Gekürzt statt abgewiesen: ein ungewöhnlich langer Transaktionsname ist
     * kein Grund, die Messung wegzuwerfen. Ohne Kürzung bricht die Einfügung ab,
     * und dann fehlt die ganze Transaktion samt ihrer Einzelschritte.
     */
    public static function text(mixed $value, int $limit): ?string
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        // `Str::limit` mit leerem Anhang: die Kürzung soll den Wert nicht mit
        // Auslassungspunkten verfälschen, an denen später niemand erkennt, ob
        // sie gemeldet oder ergänzt wurden.
        return Str::limit($value, $limit, '');
    }

    /**
     * Ein Unterbaum, der ein Objekt sein muss — `{"a":1}`, nicht `[1,2]`.
     *
     * Der Unterschied ist keine Förmlichkeit: `data`, `contexts` und
     * `measurements` werden als Feld-Baum abgelegt und später nach Namen
     * gelesen. Eine Liste an derselben Stelle ist eine Fehlmeldung des SDK, und
     * abgelegt würde sie jede Auswertung darauf zum Absturz bringen.
     *
     * @return array<string, mixed>|null
     */
    public static function map(mixed $value): ?array
    {
        if (! is_array($value) || $value === []) {
            return null;
        }

        return array_is_list($value) ? null : $value;
    }
}
