<?php

namespace App\Support\Performance;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Das kleine Suchformat der Performance-Übersicht.
 *
 * Zwei Regeln, mehr nicht:
 *
 *   - `op:<wert>` sucht eine bestimmte Operation (`op:http.server`), genau.
 *   - alles andere ist Freitext und trifft, wo es im Namen **oder** in der
 *     Operation vorkommt.
 *
 * Mehrere durch Leerzeichen getrennte Begriffe werden UND-verknüpft: wer
 * `checkout op:http.server` eingibt, meint beides. Kein ODER, keine Klammern,
 * keine Verneinung — die vollständige Suchsyntax ist eine Aufgabe für sich (die
 * S-Phase), und ein halbes Abfrage-Sprachlein, das an der zweiten Klammer
 * scheitert, ist schlechter als eine Suche, deren Grenzen man in zwei Sätzen
 * erklären kann.
 *
 * Gesucht wird **vor** dem Gruppieren. Das ist kein Detail: so trägt die Suche
 * die Menge der Zeilen herunter, statt hinterher aus fertigen Gruppen
 * auszusortieren.
 */
final class TransactionSearch
{
    /**
     * Fluchtzeichen für die Platzhalter von `LIKE`.
     *
     * Nicht der Rückstrich. MySQL deutet ihn in Zeichenketten selbst noch einmal
     * um, SQLite kennt gar keine Rückstrich-Fluchten — `ESCAPE '\\'` bedeutet in
     * beiden etwas anderes. Ein Ausrufezeichen steht in beiden für sich selbst.
     */
    private const ESCAPE = '!';

    /**
     * Das einzige Schlüsselwort des Formats.
     */
    private const OP_PREFIX = 'op:';

    /**
     * @param  list<string>  $terms  Freitext-Begriffe
     * @param  list<string>  $ops  ausdrücklich verlangte Operationen
     */
    private function __construct(
        public readonly string $input,
        private readonly array $terms,
        private readonly array $ops,
    ) {}

    /**
     * Liest die Eingabe der Adresszeile.
     */
    public static function parse(?string $input): self
    {
        $input = trim((string) $input);
        $parts = preg_split('/\s+/u', $input, -1, PREG_SPLIT_NO_EMPTY);

        $terms = [];
        $ops = [];

        foreach ($parts === false ? [] : $parts as $part) {
            if (! str_starts_with($part, self::OP_PREFIX)) {
                $terms[] = $part;

                continue;
            }

            $op = substr($part, strlen(self::OP_PREFIX));

            // Ein `op:` ohne Wert schränkt nichts ein. Es als „Operation ist
            // leer" zu lesen wäre die formal richtige, praktisch aber falsche
            // Auslegung: es ist eine halb getippte Eingabe, und die soll die
            // Liste nicht leeren.
            if ($op !== '') {
                $ops[] = $op;
            }
        }

        return new self($input, $terms, $ops);
    }

    public function isEmpty(): bool
    {
        return $this->terms === [] && $this->ops === [];
    }

    /**
     * Schränkt eine Abfrage über die Vorberechnung auf die Treffer ein.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function apply(Builder $query): Builder
    {
        foreach ($this->ops as $op) {
            $query->where('op', $op);
        }

        foreach ($this->terms as $term) {
            $pattern = '%'.self::escape($term).'%';

            $query->where(function (Builder $inner) use ($pattern): void {
                // Über `whereRaw`, weil der Abfrage-Erzeuger kein `ESCAPE`
                // mitgibt. Ohne das Fluchtzeichen wäre ein `%` in der Eingabe ein
                // Platzhalter statt eines Prozentzeichens.
                //
                // Groß- und Kleinschreibung sind beiden Datenbanken bei `LIKE`
                // von sich aus gleichgültig: MySQL wegen der Sortierfolge
                // (`utf8mb4_unicode_ci`), SQLite für ASCII von Haus aus.
                $inner
                    ->whereRaw('name like ? escape \''.self::ESCAPE.'\'', [$pattern])
                    ->orWhereRaw('op like ? escape \''.self::ESCAPE.'\'', [$pattern]);
            });
        }

        return $query;
    }

    /**
     * Macht die Platzhalter von `LIKE` zu gewöhnlichen Zeichen. Das
     * Fluchtzeichen selbst zuerst, sonst würde die zweite Ersetzung die erste
     * wieder aufbrechen.
     */
    private static function escape(string $term): string
    {
        return str_replace(
            [self::ESCAPE, '%', '_'],
            [self::ESCAPE.self::ESCAPE, self::ESCAPE.'%', self::ESCAPE.'_'],
            $term,
        );
    }
}
