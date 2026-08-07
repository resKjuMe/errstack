<?php

namespace App\Support\Ingest\Grouping;

use App\Enums\GroupingSource;

/**
 * Das Ergebnis der Gruppierung: der Hash und die Begründung dafür.
 *
 * Der Hash ist die Zusage dieser Aufgabe: **gleiche Eingabe ergibt dauerhaft
 * dieselbe Gruppe.** Daran hängt mehr, als es zunächst aussieht — verschiebt
 * sich der Hash, entsteht für einen Fehler, der seit Monaten läuft, eine zweite
 * Gruppe, seine Zählung beginnt bei eins, und jede Alarmregel, die auf ihn
 * zeigt, meldet einen neuen Fehler. Deshalb ist alles, was in den Hash eingeht,
 * ausdrücklich benannt und nirgends beiläufig.
 *
 * Die Begründung ({@see $source}, {@see $components}, {@see $rule}) wird am
 * Ereignis mitgespeichert. Ohne sie wäre der Hash eine Zeichenkette ohne
 * Herkunft: man sähe, dass zwei Meldungen zusammengefasst wurden, aber nicht,
 * wonach — und könnte weder eine Regel schreiben noch eine falsche erkennen.
 */
final class Fingerprint
{
    /**
     * Trennzeichen zwischen den Bestandteilen.
     *
     * Ein Zeichen, das in keinem Feldnamen und in keinem Pfad vorkommt: sonst
     * ließen sich zwei verschiedene Zerlegungen zu derselben Zeichenkette
     * zusammensetzen, und zwei Fehler bekämen denselben Hash.
     */
    private const SEPARATOR = "\x1f";

    /**
     * @param  list<string>  $values  Die Bestandteile in der Form, in der sie in
     *                                den Hash eingegangen sind.
     * @param  list<Component>  $components  Die benannten Bestandteile, soweit sie
     *                                       aus dem Standardverfahren stammen.
     */
    public function __construct(
        public readonly string $hash,
        public readonly GroupingSource $source,
        public readonly array $values,
        public readonly array $components = [],
        public readonly ?int $ruleId = null,
    ) {}

    /**
     * Bildet den Fingerabdruck aus fertigen Bestandteilen.
     *
     * @param  list<string>  $values
     * @param  list<Component>  $components
     */
    public static function of(
        GroupingSource $source,
        array $values,
        array $components = [],
        ?int $ruleId = null,
    ): self {
        return new self(
            hash: self::hash($values),
            source: $source,
            values: $values,
            components: $components,
            ruleId: $ruleId,
        );
    }

    /**
     * Bildet den Fingerabdruck aus dem Ergebnis des Standardverfahrens.
     */
    public static function fromComponents(Components $components, ?int $ruleId = null): self
    {
        return self::of(
            source: $components->source,
            values: $components->values(),
            components: $components->components,
            ruleId: $ruleId,
        );
    }

    /**
     * Der Hash über die Bestandteile.
     *
     * SHA-256 auf 32 Zeichen gekürzt. Die Kürzung ist der Platz in der Tabelle
     * und im Index; 128 Bit reichen dafür bei weitem — die Frage ist nicht
     * Fälschungssicherheit, sondern ob zwei verschiedene Fehler zufällig
     * zusammenfallen, und dafür ist der Abstand zur Zahl der Fehler auf der Welt
     * groß genug.
     *
     * Warum nicht MD5, wie Sentry es tut: es tut hier dasselbe, kostet aber in
     * jeder Sicherheitsprüfung eine Erklärung, die niemand liest.
     *
     * @param  list<string>  $values
     */
    public static function hash(array $values): string
    {
        return substr(hash('sha256', implode(self::SEPARATOR, $values)), 0, 32);
    }

    /**
     * Die Begründung, wie sie am Ereignis abgelegt wird.
     *
     * @return array{source: string, values: list<string>, components: list<array{name: string, value: string}>, rule_id: int|null}
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source->value,
            'values' => $this->values,
            'components' => array_map(
                static fn (Component $component): array => $component->toArray(),
                $this->components,
            ),
            'rule_id' => $this->ruleId,
        ];
    }
}
