<?php

namespace App\Support\Ingest\Grouping;

/**
 * Eine Bedingung einer Fingerprint-Regel: „Feld sieht so aus".
 *
 * Das Muster ist ein Platzhalter-Ausdruck (`*`, `?`) und **kein** regulärer
 * Ausdruck. Das ist eine bewusste Einschränkung: die Regeln schreibt, wer eine
 * Fehlerliste aufräumen will, nicht wer reguläre Ausdrücke schreiben will. Ein
 * `*vendor/*` ist in dreißig Sekunden richtig, ein `.*\/vendor\/.*` ist in
 * dreißig Sekunden falsch — und ein falscher regulärer Ausdruck kann in einem
 * Schritt, der bei jeder Meldung läuft, teuer werden.
 *
 * Ein Muster ohne Platzhalter trifft **genau**. Wer „enthält" meint, schreibt
 * `*text*` — sichtbar und nicht als stille Annahme.
 *
 * Verglichen wird ohne Rücksicht auf Groß- und Kleinschreibung: Ausnahme-Typen
 * und Pfade werden je nach Plattform verschieden geschrieben, und ein Regelwerk,
 * das daran scheitert, ist schwer zu erklären.
 */
final class Matcher
{
    public function __construct(
        public readonly string $attribute,
        public readonly string $pattern,
        /** Kehrt die Bedingung um: die Regel greift, wenn das Muster **nicht** passt. */
        public readonly bool $negated = false,
    ) {}

    /**
     * @param  array<mixed>  $matcher  Wie die Bedingung in der Datenbank steht —
     *                                 geprüft wird sie hier, nicht vom Aufrufer.
     */
    public static function fromArray(array $matcher): ?self
    {
        $attribute = $matcher['attribute'] ?? null;
        $pattern = $matcher['pattern'] ?? null;

        if (! is_string($attribute) || ! is_string($pattern) || trim($attribute) === '' || $pattern === '') {
            return null;
        }

        return new self(
            attribute: strtolower(trim($attribute)),
            pattern: $pattern,
            negated: (bool) ($matcher['negated'] ?? false),
        );
    }

    /**
     * Trifft die Bedingung auf diese Meldung zu?
     *
     * Bei mehrwertigen Feldern — allen `stack.*` und mehrfach vorkommenden
     * Ausnahmen — genügt **ein** Treffer. Das ist die Lesart, die man erwartet:
     * `stack.path:*abrechnung/*` heißt „irgendwo im Stapel steht die
     * Abrechnung", nicht „der ganze Stapel liegt in der Abrechnung".
     *
     * Bei der Umkehrung dreht sich damit auch die Menge: `!stack.path:*vendor/*`
     * greift nur, wenn **kein** Rahmen im Vendor-Verzeichnis liegt. Alles andere
     * wäre bei einem Stacktrace aus zweihundert Rahmen wertlos.
     */
    public function matches(Attributes $attributes): bool
    {
        $values = $attributes->all($this->attribute);

        $hit = false;

        foreach ($values as $value) {
            if ($this->matchesValue($value)) {
                $hit = true;

                break;
            }
        }

        return $this->negated ? ! $hit : $hit;
    }

    private function matchesValue(string $value): bool
    {
        return preg_match($this->regex(), $value) === 1;
    }

    /**
     * Übersetzt das Muster in einen regulären Ausdruck.
     *
     * Alles außer `*` und `?` wird maskiert — das ist der Punkt der ganzen
     * Klasse: was jemand in ein Textfeld schreibt, soll ein Muster sein und
     * nicht versehentlich ein Programm.
     */
    private function regex(): string
    {
        $quoted = preg_quote($this->pattern, '#');

        $translated = str_replace(['\*', '\?'], ['.*', '.'], $quoted);

        return '#^'.$translated.'$#iu';
    }

    /**
     * @return array{attribute: string, pattern: string, negated: bool}
     */
    public function toArray(): array
    {
        return [
            'attribute' => $this->attribute,
            'pattern' => $this->pattern,
            'negated' => $this->negated,
        ];
    }
}
