<?php

namespace App\Support\Search\Ast;

use App\Support\Search\FieldResolver;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Ein Und: alle Teilaussagen müssen zutreffen.
 *
 * Es entsteht auch ohne das Wort `and` — zwei nebeneinander stehende Begriffe
 * sind ein Und, weil man eine Suche so tippt. `is:unresolved browser:Chrome`
 * meint beides und nicht eines von beiden.
 */
final class AllOf implements Expression
{
    /**
     * @param  list<Expression>  $children
     */
    public function __construct(public readonly array $children) {}

    public function compile(FieldResolver $fields): ?Closure
    {
        $compiled = [];

        foreach ($this->children as $child) {
            $one = $child->compile($fields);

            // Ein Teil ohne Einschränkung fällt weg, der Rest gilt weiter: bei
            // einem Und ist „und alles" dasselbe wie nichts zu sagen.
            if ($one !== null) {
                $compiled[] = $one;
            }
        }

        if ($compiled === []) {
            return null;
        }

        return function (Builder $query) use ($compiled): void {
            // Als eigene Gruppe, damit ein umgebendes Oder nicht in die
            // Einzelteile hineingreift.
            $query->where(function (Builder $inner) use ($compiled): void {
                foreach ($compiled as $apply) {
                    $inner->where(fn (Builder $one) => $apply($one));
                }
            });
        };
    }
}
