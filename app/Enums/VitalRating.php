<?php

namespace App\Enums;

use App\Models\WebVitalAggregate;

/**
 * Die Bewertung eines Messwerts: gut, mäßig, schlecht.
 *
 * Drei Klassen und nicht zwei, weil die mittlere die eigentliche Arbeit macht.
 * Mit nur „gut/schlecht" stünde jede Seite knapp über der Schwelle neben einer,
 * die dreimal so lange braucht — und die Liste der zu behebenden Seiten wäre so
 * lang, dass sie niemand abarbeitet. „Mäßig" trennt „hier lohnt sich der
 * nächste Schritt" von „hier brennt es".
 *
 * Die Aufzählung wird **gespeichert** (als drei Zähler je Zeitfenster, siehe
 * {@see WebVitalAggregate}) und nicht bei jeder Anzeige neu
 * gerechnet. Das ist der Grund, warum die Bewertung einer Seite exakt ist,
 * obwohl die Werte selbst nur als Verteilung vorliegen: gezählt wird beim
 * Eintreffen der Messung, mit dem genauen Wert.
 */
enum VitalRating: string
{
    case Good = 'good';

    case NeedsImprovement = 'needs_improvement';

    case Poor = 'poor';

    /**
     * Von der besten zur schlechtesten Klasse — die Reihenfolge, in der die
     * Verteilung gezeichnet und in der das Perzentil gesucht wird.
     *
     * @return list<self>
     */
    public static function ordered(): array
    {
        return [self::Good, self::NeedsImprovement, self::Poor];
    }

    public function label(): string
    {
        return __('enums.vital_rating.'.$this->value);
    }

    /**
     * Der Name der Spalte, in der Messungen dieser Klasse gezählt werden.
     *
     * Er steht hier und nicht an drei Stellen im SQL: die Aufnahme, die
     * Zusammenfassung und die Anzeige meinen dieselbe Spalte, und ein Tippfehler
     * in einer davon wäre eine Zahl, die stillschweigend fehlt.
     */
    public function column(): string
    {
        return $this->value.'_count';
    }

    /**
     * Ist diese Klasse schlechter als die andere?
     */
    public function worseThan(self $other): bool
    {
        return $this->weight() > $other->weight();
    }

    /**
     * Wie schwer eine Messung dieser Klasse wiegt, wenn die schlechtesten Seiten
     * gesucht werden.
     *
     * Eine schlechte Messung zählt doppelt gegenüber einer mäßigen, eine gute
     * gar nicht. Damit ist die Rangfolge der Übersicht eine **Anzahl** — „so
     * viele Ladevorgänge waren nicht in Ordnung" — und keine abstrakte Punktzahl:
     * eine Seite mit 10.000 mäßigen Aufrufen steht vor einer mit fünf schlechten,
     * und das ist die richtige Reihenfolge, wenn man den Aufwand für die
     * Behebung gegen seine Wirkung hält.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Good => 0,
            self::NeedsImprovement => 1,
            self::Poor => 2,
        };
    }
}
