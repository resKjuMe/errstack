<?php

namespace App\Support\Search\Ast;

use App\Support\Search\FieldResolver;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Ein Oder: eine der Teilaussagen genügt.
 *
 * **Ein Teil ohne Einschränkung macht das ganze Oder wirkungslos** — und das ist
 * kein Nachlassen, sondern die einzige richtige Antwort. „A oder etwas, das
 * immer zutrifft" trifft immer zu. Wer `is:unresolved or bookmarks:mir` sucht,
 * solange es keine Merkzettel gibt, bekommt deshalb die ungefilterte Liste und
 * den Hinweis, welcher Teil nicht ausgewertet werden konnte — und nicht
 * stillschweigend die offenen Fehler, als wäre der zweite Teil nie dagewesen.
 */
final class AnyOf implements Expression
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

            if ($one === null) {
                return null;
            }

            $compiled[] = $one;
        }

        if ($compiled === []) {
            return null;
        }

        return function (Builder $query) use ($compiled): void {
            $query->where(function (Builder $inner) use ($compiled): void {
                foreach ($compiled as $apply) {
                    $inner->orWhere(fn (Builder $one) => $apply($one));
                }
            });
        };
    }
}
