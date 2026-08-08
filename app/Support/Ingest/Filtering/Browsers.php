<?php

namespace App\Support\Ingest\Filtering;

/**
 * Die Untergrenzen für Browser-Fassungen — Schreibweise, Auswertung, Vergleich.
 *
 * Ein Eintrag ist entweder `safari:6` („alles unter 6") oder `ie` („jede
 * Fassung"). Zwei Formen statt einer, weil es zwei Fälle gibt: bei den meisten
 * Browsern ist irgendwann eine Fassung alt genug, beim Internet Explorer ist es
 * der Browser selbst.
 *
 * Verglichen wird nur die **Hauptfassung**. `16.4` und `16.4.1` unterscheidet
 * niemand, der eine Untergrenze zieht, und ein Vergleich über alle Stellen
 * würde `9.1` für kleiner als `9.0.5` halten, sobald man ihn als Zahl schreibt.
 */
final class Browsers
{
    /**
     * Ist dieser Browser nach den Einträgen veraltet?
     *
     * @param  array{name: string, version: string}  $browser
     * @param  list<string>  $expressions
     */
    public static function isLegacy(array $browser, array $expressions): bool
    {
        foreach ($expressions as $expression) {
            [$name, $minimum] = self::parse($expression);

            if ($name === null || ! self::isSameBrowser($name, $browser['name'])) {
                continue;
            }

            if ($minimum === null) {
                return true;
            }

            $version = self::major($browser['version']);

            // Ohne lesbare Fassungsangabe wird nicht gefiltert: „unbekannt" ist
            // kein Beleg für „zu alt", und die Meldung wegzuwerfen wäre hier
            // der teurere Irrtum.
            if ($version !== null && $version < $minimum) {
                return true;
            }
        }

        return false;
    }

    /**
     * Die Vorgaben als Einträge, wie sie auch jemand von Hand schriebe.
     *
     * Dieselbe Form für Vorgabe und Eintrag: sonst gäbe es zwei Wege, eine
     * Grenze auszudrücken, und der Filter müsste beide auswerten.
     *
     * @return list<string>
     */
    public static function defaults(): array
    {
        $expressions = [];

        foreach (Defaults::BROWSER_VERSIONS as $name => $minimum) {
            $expressions[] = $minimum === null ? $name : $name.':'.$minimum;
        }

        return $expressions;
    }

    /**
     * Ist der Eintrag brauchbar? Die Prüfung des Formulars stellt dieselbe
     * Frage wie die Auswertung — mit derselben Antwort, weil es dieselbe
     * Zerlegung ist.
     */
    public static function isValid(string $expression): bool
    {
        return self::parse($expression)[0] !== null;
    }

    /**
     * Zerlegt `safari:6` in Namen und Untergrenze.
     *
     * @return array{0: string|null, 1: int|null}
     */
    private static function parse(string $expression): array
    {
        $parts = explode(':', trim($expression), 2);
        $name = strtolower(trim($parts[0]));

        if ($name === '') {
            return [null, null];
        }

        if (count($parts) === 1) {
            return [$name, null];
        }

        $minimum = trim($parts[1]);

        if ($minimum === '' || ! ctype_digit($minimum)) {
            return [null, null];
        }

        return [$name, (int) $minimum];
    }

    /**
     * Meint der Eintrag diesen Browser?
     *
     * Der Name muss genau stimmen (Groß- und Kleinschreibung ausgenommen). Das
     * ist strenger, als es sein müsste, und mit Absicht: `opera` als Teiltreffer
     * würde auch `Opera Mini` sperren, obwohl das ein anderer Browser mit einer
     * eigenen Zählung ist — Fassung 8 ist dort aktuell und hier steinalt. Die
     * Vorgabeliste führt die Schreibweisen deshalb einzeln auf, statt sich auf
     * einen Teiltreffer zu verlassen.
     */
    private static function isSameBrowser(string $expression, string $name): bool
    {
        return $expression === $name;
    }

    /**
     * Die Hauptfassung aus `16.4.1`, `11.0` oder `4`.
     */
    private static function major(string $version): ?int
    {
        if (preg_match('/^\s*(\d+)/', $version, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }
}
