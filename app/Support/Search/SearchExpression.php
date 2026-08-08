<?php

namespace App\Support\Search;

use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Eine Sucheingabe, fertig übersetzt: Text hinein, Einschränkung heraus.
 *
 * Der Weg dahin sind drei Schritte — zerlegen, zu einem Ausdrucksbaum
 * zusammensetzen, mit einem {@see FieldResolver} in eine Abfrage übersetzen —,
 * und sie passieren **alle beim Erzeugen**. Das ist Absicht: der Aufrufer soll
 * wissen, ob die Eingabe verstanden wurde, **bevor** er eine Seite baut. Träte
 * der Fehler erst beim Abfragen auf, käme er mitten aus dem Blättern und wäre
 * dort nur noch als Ausnahme zu behandeln.
 *
 * **Eine unverständliche Eingabe ist kein Abbruch.** Sie wird zu {@see error}
 * und schränkt nichts ein — die Liste steht ungefiltert da, und die Oberfläche
 * sagt, an welcher Stelle es klemmt. Die Alternative wäre eine leere Seite mit
 * einer Fehlermeldung: formal richtig, praktisch die Sackgasse, aus der man nur
 * durch Löschen der Adresszeile herausfindet.
 */
final class SearchExpression
{
    /**
     * @param  (Closure(Builder<*>): void)|null  $constraint
     * @param  list<string>  $unavailable
     */
    private function __construct(
        public readonly string $input,
        private readonly ?Closure $constraint,
        public readonly ?SearchSyntaxException $error,
        public readonly array $unavailable,
    ) {}

    /**
     * Übersetzt eine Eingabe für eine bestimmte Welt.
     */
    public static function compile(?string $input, FieldResolver $fields): self
    {
        $input = trim((string) $input);

        if ($input === '') {
            return new self('', null, null, []);
        }

        try {
            $expression = Parser::parse($input);
            $constraint = $expression?->compile($fields);
        } catch (SearchSyntaxException $error) {
            return new self($input, null, $error, []);
        }

        return new self($input, $constraint, null, $fields->unavailable());
    }

    /**
     * Legt die Suche auf eine Abfrage.
     *
     * @param  Builder<*>  $query
     */
    public function apply(Builder $query): void
    {
        if ($this->constraint !== null) {
            ($this->constraint)($query);
        }
    }
}
