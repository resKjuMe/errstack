<?php

namespace App\Enums;

/**
 * Woran eine eigene Datenschutz-Regel greift.
 *
 * Zwei Arten genügen, und sie beantworten zwei verschiedene Fragen. Der
 * Feldname trifft, wo die Anwendung selbst schon weiß, was drinsteht
 * (`kundennummer`) — er kostet einen Vergleich und trifft zuverlässig. Das
 * Muster trifft, wo der Feldname nichts verrät: eine Kartennummer im Fließtext
 * einer Fehlermeldung steht in keinem Feld, das so heißt.
 *
 * Beides zusammen ist nötig, weil keins von beiden allein reicht: nach Feldnamen
 * allein bliebe jeder eingebettete Wert stehen, nach Mustern allein müsste für
 * jede Kundennummern-Schreibweise ein regulärer Ausdruck gefunden werden.
 */
enum ScrubRuleType: string
{
    /**
     * Der Name eines Feldes. Groß- und Kleinschreibung spielt keine Rolle, und
     * `*` steht für beliebig viele Zeichen (`kunden_*`) — ohne den Platzhalter
     * bräuchte eine Anwendung mit `kunden_id`, `kunden_nr` und `kunden_name`
     * drei Regeln für dieselbe Sache.
     */
    case Field = 'field';

    /**
     * Ein regulärer Ausdruck auf den **Wert**. Ersetzt wird der Treffer, nicht
     * das ganze Feld: eine Kartennummer steckt meist mitten in einem Satz, und
     * den Satz mitzunehmen kostet die Auskunft, um die es eigentlich geht.
     */
    case Pattern = 'pattern';

    public function label(): string
    {
        return __('enums.scrub_rule_type.'.$this->value);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $type) => ['value' => $type->value, 'label' => $type->label()],
            self::cases(),
        );
    }
}
