<?php

namespace App\Http\Requests;

use App\Enums\TransactionSort;
use App\Models\Transaction;
use App\Support\Performance\TransactionSearch;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Die Eingabe der Performance-Übersicht: die globale Filterleiste plus das, was
 * nur diese Seite kennt — Suche, Sortierung und Seitenzahl.
 *
 * Sie erweitert {@see GlobalFilterRequest}, statt dessen Felder abzuschreiben.
 * Der ganze Zustand der Seite steht damit in der Adresszeile, nach denselben
 * Regeln wie überall: ein Neuladen behält ihn, und ein geteilter Link zeigt
 * beim Empfänger dieselbe Liste in derselben Reihenfolge.
 */
class PerformanceOverviewRequest extends GlobalFilterRequest
{
    /**
     * Längste Sucheingabe. Großzügig gegenüber einem Transaktionsnamen
     * ({@see Transaction::NAME_LIMIT}) und knapp genug, dass niemand
     * eine Adresszeile als Ablage benutzt.
     */
    public const SEARCH_LIMIT = 200;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // Zusammengeführt und nicht ersetzt: die Felder der Filterleiste müssen
        // stehen bleiben, sonst fielen sie stillschweigend aus `validated()`
        // heraus und die Seite zeigte den Standard-Zeitraum, egal was in der
        // Adresszeile steht.
        return parent::rules() + [
            'q' => ['nullable', 'string', 'max:'.self::SEARCH_LIMIT],
            'sort' => ['nullable', Rule::in(TransactionSort::values())],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * Die Sucheingabe, wie sie in das Feld zurückgeschrieben wird.
     */
    public function searchInput(): string
    {
        $input = $this->validated('q');

        return is_string($input) ? trim($input) : '';
    }

    public function search(): TransactionSearch
    {
        return TransactionSearch::parse($this->searchInput());
    }

    public function sort(): TransactionSort
    {
        return TransactionSort::fromInput($this->validated('sort'));
    }

    /**
     * Absteigend, solange nichts anderes dasteht: die Voreinstellung der Seite
     * ist „das größte Problem zuerst", und das ist der größte Wert.
     */
    public function descending(): bool
    {
        return $this->validated('direction') !== 'asc';
    }

    public function page(): int
    {
        $page = $this->validated('page');

        return is_numeric($page) ? max(1, (int) $page) : 1;
    }
}
