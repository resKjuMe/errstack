<?php

namespace App\Support\Api;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Blätterung und Sortierung für Listen der öffentlichen Schnittstelle.
 *
 * Beides kommt aus der Adresszeile: `?page=2&per_page=25&sort=-created_at`. Ein
 * führendes Minus sortiert absteigend — dieselbe Schreibweise wie bei Sentry und
 * kurz genug, dass man sie von Hand tippen kann.
 *
 * Sortiert wird nur nach ausdrücklich erlaubten Feldern. Die Alternative — die
 * Angabe durchreichen — wäre eine offene Tür in die Tabellenstruktur.
 */
final class ApiQuery
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, string>  $sortable  Name nach außen => Spalte in der Datenbank
     * @param  string  $default  Vorgabe-Sortierung in derselben Schreibweise wie `sort`
     * @return LengthAwarePaginator<int, TModel>
     */
    public static function paginate(Builder $query, Request $request, array $sortable, string $default): LengthAwarePaginator
    {
        $maxPerPage = (int) config('api.pagination.max_per_page', 100);

        $validated = Validator::make($request->query(), [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.$maxPerPage],
            'sort' => ['sometimes', 'string', Rule::in(self::allowedSorts($sortable))],
        ], [], [
            'page' => 'Seite',
            'per_page' => 'Einträge je Seite',
            'sort' => 'Sortierung',
        ])->validate();

        $sort = is_string($validated['sort'] ?? null) ? $validated['sort'] : $default;
        $descending = str_starts_with($sort, '-');
        $column = $sortable[ltrim($sort, '-')] ?? $sortable[ltrim($default, '-')] ?? 'id';

        $query->orderBy($column, $descending ? 'desc' : 'asc');

        // Zweitschlüssel: ohne ihn ist die Reihenfolge bei gleichen Werten
        // (z. B. zwei Tokens derselben Sekunde) offen — und ein Eintrag kann
        // beim Blättern doppelt oder gar nicht erscheinen.
        $query->orderBy($query->getModel()->getQualifiedKeyName(), $descending ? 'desc' : 'asc');

        return $query->paginate(
            perPage: (int) ($validated['per_page'] ?? config('api.pagination.per_page', 50)),
            page: (int) ($validated['page'] ?? 1),
        )->withQueryString();
    }

    /**
     * Jedes erlaubte Feld gilt in beiden Richtungen — mit und ohne Minus.
     *
     * @param  array<string, string>  $sortable
     * @return list<string>
     */
    private static function allowedSorts(array $sortable): array
    {
        $allowed = [];

        foreach (array_keys($sortable) as $field) {
            $allowed[] = $field;
            $allowed[] = '-'.$field;
        }

        return $allowed;
    }
}
