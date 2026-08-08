<?php

namespace App\Support\Search\Ast;

use App\Support\Search\FieldResolver;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Eine Verneinung: `!level:info`, `!(browser:Chrome or browser:Edge)`.
 *
 * Verneint wird die fertige Einschränkung und nicht der Vergleich darin. Der
 * Unterschied zeigt sich bei Merkmalen: `!browser:Chrome` heißt „trägt **kein**
 * Merkmal browser=Chrome" — und findet damit auch die Fehler, bei denen gar kein
 * Browser bekannt ist. Als verneinter Vergleich innerhalb der Existenzprüfung
 * hieße es „trägt ein Merkmal browser, das nicht Chrome ist", und die Fehler
 * ohne Browser fielen heraus. Gefragt war das erste.
 */
final class Negation implements Expression
{
    public function __construct(public readonly Expression $inner) {}

    public function compile(FieldResolver $fields): ?Closure
    {
        $compiled = $this->inner->compile($fields);

        // Was sich nicht einschränken lässt, lässt sich auch nicht verneinen.
        if ($compiled === null) {
            return null;
        }

        return function (Builder $query) use ($compiled): void {
            $query->whereNot(fn (Builder $inner) => $compiled($inner));
        };
    }
}
