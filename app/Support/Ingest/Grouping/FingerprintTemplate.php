<?php

namespace App\Support\Ingest\Grouping;

/**
 * Setzt einen von Hand angegebenen Fingerabdruck zusammen.
 *
 * Solche Angaben kommen aus zwei Quellen — vom SDK (`fingerprint: [...]` an der
 * Meldung) und aus einer projektweiten Regel. Beide werden gleich behandelt,
 * weil sie dasselbe sind: jemand weiß es besser als das Standardverfahren.
 *
 * Zwei Formen von Platzhaltern:
 *
 * **`{{ default }}`** wird durch die Bestandteile des Standardverfahrens
 * ersetzt. Das ist die wichtigste Form und der Grund, warum die Bestandteile
 * überhaupt getrennt vom Hash geführt werden: `["{{ default }}", "{{ tags.mandant }}"]`
 * heißt „gruppiere wie immer, aber je Mandant getrennt". Ohne diesen Platzhalter
 * müsste man zwischen dem Standardverfahren und einer eigenen Angabe wählen,
 * statt sie zu verfeinern.
 *
 * **`{{ feld }}`** wird durch den Wert des Feldes ersetzt ({@see Attributes}),
 * auch mitten im Text: `"abrechnung-{{ error.type }}"` ist erlaubt.
 *
 * Ein Feld, das die Meldung nicht hat, wird zu {@see UNSET}. Es einfach
 * wegzulassen wäre schlimmer: dann hinge die Zahl der Bestandteile daran, was
 * eine einzelne Meldung mitbrachte, und derselbe Fehler bekäme mit und ohne
 * Marke zwei Gruppen.
 */
final class FingerprintTemplate
{
    /**
     * Der Platzhalter, der die Bestandteile des Standardverfahrens einsetzt.
     */
    public const DEFAULT = 'default';

    /**
     * Was anstelle eines Feldes steht, das die Meldung nicht hat.
     */
    public const UNSET = '<none>';

    /**
     * Wie viele Bestandteile eine Angabe haben darf.
     *
     * Die Grenze schützt den Hash nicht — der ist immer gleich lang —, sondern
     * die Begründung, die am Ereignis mitgespeichert wird. Ein SDK mit einer
     * Schleife im Fingerabdruck würde sonst je Meldung ein Vielfaches ihrer
     * eigenen Größe anlegen.
     */
    private const MAX_VALUES = 32;

    /**
     * Wie lang ein einzelner Bestandteil sein darf.
     */
    private const MAX_LENGTH = 400;

    /**
     * Löst die Platzhalter auf.
     *
     * @param  list<string>  $values  Die Angabe, wie sie geschrieben wurde.
     * @return list<string> Die Bestandteile, aus denen der Hash entsteht.
     */
    public static function expand(array $values, Attributes $attributes, Components $default): array
    {
        $expanded = [];

        foreach ($values as $value) {
            if (count($expanded) >= self::MAX_VALUES) {
                break;
            }

            if (self::isDefault($value)) {
                foreach ($default->values() as $component) {
                    if (count($expanded) >= self::MAX_VALUES) {
                        break;
                    }

                    $expanded[] = $component;
                }

                continue;
            }

            $expanded[] = self::substitute($value, $attributes);
        }

        return $expanded;
    }

    /**
     * Ist dieser Eintrag der Platzhalter für das Standardverfahren?
     *
     * Auch `{{default}}` ohne Leerzeichen — die Schreibweise wechselt zwischen
     * den SDKs, und einen Fingerabdruck an einem Leerzeichen scheitern zu
     * lassen wäre die Art Fehler, die niemand findet.
     */
    public static function isDefault(string $value): bool
    {
        return preg_match('/^\{\{\s*'.self::DEFAULT.'\s*\}\}$/i', trim($value)) === 1;
    }

    /**
     * Ersetzt die Feld-Platzhalter in einem Eintrag.
     */
    private static function substitute(string $value, Attributes $attributes): string
    {
        $substituted = preg_replace_callback(
            '/\{\{\s*([a-z][a-z0-9_.]*)\s*\}\}/i',
            static function (array $match) use ($attributes): string {
                /** @var array{0: string, 1: string} $match */
                if (strcasecmp($match[1], self::DEFAULT) === 0) {
                    // `{{ default }}` mitten im Text: die Bestandteile lassen
                    // sich dort nicht sinnvoll einsetzen — als eigener Eintrag
                    // ist der Platzhalter oben schon abgehandelt.
                    return self::UNSET;
                }

                return $attributes->value($match[1]) ?? self::UNSET;
            },
            $value,
        ) ?? $value;

        $substituted = trim($substituted);

        if (mb_strlen($substituted) > self::MAX_LENGTH) {
            $substituted = mb_substr($substituted, 0, self::MAX_LENGTH);
        }

        // Ein leerer Eintrag bleibt ein Eintrag — siehe {@see UNSET}: die Zahl
        // der Bestandteile darf nicht von der einzelnen Meldung abhängen.
        return $substituted === '' ? self::UNSET : $substituted;
    }
}
