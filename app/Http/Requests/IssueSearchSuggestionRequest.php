<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Eine halb getippte Sucheingabe samt Stelle des Schreibmarkers.
 *
 * Erbt die Felder der Filterleiste, weil die Vorschläge davon abhängen: welche
 * Merkmale und Versionen es gibt, entscheidet die Projektauswahl. Ein Vorschlag
 * `browser:Safari` aus einem Projekt, das gar nicht angezeigt wird, führte auf
 * eine leere Liste.
 */
class IssueSearchSuggestionRequest extends GlobalFilterRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return parent::rules() + [
            // Dieselbe Grenze wie in der Suchleiste selbst — sie ist es, die
            // hier gleich stehen soll.
            'q' => ['nullable', 'string', 'max:500'],
            'cursor' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * Die Eingabe, wie sie im Feld steht — unfertig und ungeprüft.
     */
    public function term(): string
    {
        return (string) ($this->validated('q') ?? '');
    }

    /**
     * Die Stelle des Schreibmarkers in Zeichen.
     *
     * Ohne Angabe das Ende der Eingabe: das ist der Fall, in dem jemand einfach
     * weitertippt, und er soll nicht die Angabe erzwingen.
     */
    public function cursor(): int
    {
        $cursor = $this->validated('cursor');

        return $cursor === null ? mb_strlen($this->term()) : (int) $cursor;
    }
}
