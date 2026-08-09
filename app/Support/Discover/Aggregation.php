<?php

namespace App\Support\Discover;

/**
 * Eine angeforderte Kennzahl: Rechenart und das Feld, über das sie läuft —
 * `p95(duration)`, `count()`, `count_unique(user.id)`.
 *
 * Die Schreibweise ist Absicht und nicht Zierde: sie ist die Form, in der eine
 * Kennzahl in einer Adresszeile, in einem gespeicherten Baustein (D4) und in
 * einer Alarmdefinition (A3) steht. Ein Aufrufer, der sie tippt, und einer, der
 * {@see self::of()} benutzt, meinen dasselbe — und {@see self::alias()} gibt
 * beiden denselben Namen in der Ergebniszeile.
 */
final class Aggregation
{
    private function __construct(
        public readonly Aggregate $aggregate,
        public readonly ?string $field,
    ) {}

    public static function of(Aggregate $aggregate, ?string $field = null): self
    {
        if ($aggregate->needsField() && ($field === null || trim($field) === '')) {
            throw DiscoverException::invalid('Die Rechenart '.$aggregate->value.' braucht ein Feld.');
        }

        if (! $aggregate->needsField() && $field !== null) {
            throw DiscoverException::invalid('Die Rechenart '.$aggregate->value.' nimmt kein Feld.');
        }

        return new self($aggregate, $field === null ? null : trim($field));
    }

    /**
     * Liest `p95(duration)` — und `count` genauso wie `count()`.
     */
    public static function parse(string $expression): self
    {
        $expression = trim($expression);

        if (preg_match('/^([a-z_0-9]+)\s*\(\s*(.*?)\s*\)$/i', $expression, $matches) === 1) {
            [, $name, $field] = $matches;
        } else {
            [$name, $field] = [$expression, ''];
        }

        $aggregate = Aggregate::tryFrom(mb_strtolower($name));

        if ($aggregate === null) {
            throw DiscoverException::invalid('Unbekannte Rechenart: '.$name);
        }

        return self::of($aggregate, $field === '' ? null : $field);
    }

    /**
     * Der Name dieser Kennzahl in der Ergebniszeile.
     *
     * Aus dem Feld wird dabei alles entfernt, was ein Spaltenname nicht sein
     * kann: `user.id` wird zu `user_id`, damit der Name in JSON, in einer
     * Tabellenkopfzeile und als SQL-Alias derselbe ist.
     */
    public function alias(): string
    {
        if ($this->field === null) {
            return $this->aggregate->value;
        }

        $field = mb_strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '_', $this->field));

        return $this->aggregate->value.'_'.trim($field, '_');
    }

    public function toString(): string
    {
        return $this->aggregate->value.'('.($this->field ?? '').')';
    }
}
