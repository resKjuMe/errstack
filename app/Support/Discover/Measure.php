<?php

namespace App\Support\Discover;

use Closure;

/**
 * Eine Kennzahl, übersetzt: was in die Abfrage geschrieben wird und wie aus der
 * Ergebniszeile eine Zahl wird.
 *
 * Der Grund für diesen Zwischenschritt ist die Vielfalt auf beiden Seiten. Ein
 * `count()` ist eine Spalte und ein Cast; ein `p95(duration)` sind
 * einunddreißig Klassensummen und eine Verteilungsrechnung; dasselbe `count()`
 * über die vorberechneten Fenster ist eine **Summe** und nicht ein Zählen. Würde
 * der Motor diese Fälle kennen, stünde in ihm eine Tabelle aus Quelle × Rechenart,
 * und jede neue Quelle müsste ihn ändern. So kennt er nur diesen Gegenstand: er
 * hängt {@see self::$selects} an die Abfrage und schickt jede Zeile durch
 * {@see self::$read}.
 *
 * **`$order` sagt, ob die Datenbank sortieren kann.** Was in SQL als Zahl
 * dasteht, wird dort sortiert; was erst in PHP entsteht (die Perzentile),
 * bekommt `null` — der Motor sortiert dann selbst und sagt, wenn er dafür nicht
 * alle Gruppen sehen konnte.
 */
final class Measure
{
    /**
     * @param  list<string>  $selects  Ausdrücke der Form `… as alias`
     * @param  Closure(array<string, mixed>): ?float  $read
     * @param  string|null  $order  Ausdruck, nach dem sich in SQL sortieren lässt
     */
    public function __construct(
        public readonly array $selects,
        public readonly Closure $read,
        public readonly ?string $order = null,
    ) {}

    /**
     * Der Regelfall: ein SQL-Aggregat, das schon eine Zahl ist.
     */
    public static function scalar(string $expression, string $alias, bool $integer = false): self
    {
        return new self(
            [$expression.' as '.$alias],
            static function (array $row) use ($alias, $integer): ?float {
                $value = $row[$alias] ?? null;

                if ($value === null || ! is_numeric($value)) {
                    return null;
                }

                return $integer ? (float) (int) $value : (float) $value;
            },
            $expression,
        );
    }
}
