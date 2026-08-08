<?php

namespace App\Http\Requests;

use App\Models\Transaction;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Die Eingabe der Web-Vitals-Übersicht: die globale Filterleiste plus Suche und
 * Seitenzahl.
 *
 * Keine Sortierung, anders als bei der Performance-Übersicht. Das ist Absicht:
 * die Reihenfolge dieser Liste ist ihre Aussage — „hier haben die meisten
 * Besucher ein schlechtes Erlebnis" — und ließe sich nicht sinnvoll je Spalte
 * umstellen. Eine Tabelle mit sechs Messwerten hat achtzehn Zahlen je Zeile;
 * nach einer von ihnen zu sortieren beantwortet keine Frage, die die Rangfolge
 * nicht schon beantwortet.
 */
class WebVitalOverviewRequest extends GlobalFilterRequest
{
    /**
     * Längste Sucheingabe — großzügig gegenüber einem Seitennamen und knapp
     * genug, dass niemand die Adresszeile als Ablage benutzt.
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

    /**
     * Die Sucheingabe für die Abfrage, auf die Länge eines Seitennamens
     * begrenzt.
     *
     * Länger als ein Name kann eine Übereinstimmung nicht sein — und eine
     * Eingabe, die nichts treffen kann, gehört nicht in ein `LIKE` über
     * Millionen Zeilen.
     */
    public function search(): string
    {
        return mb_substr($this->searchInput(), 0, Transaction::NAME_LIMIT);
    }

    public function page(): int
    {
        $page = $this->validated('page');

        return is_numeric($page) ? max(1, (int) $page) : 1;
    }
}
