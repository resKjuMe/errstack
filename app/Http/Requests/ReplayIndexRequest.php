<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Die Eingabe der Aufzeichnungs-Übersicht: die globale Filterleiste plus der
 * eine Schalter, den nur diese Seite kennt.
 *
 * Wie überall steht der ganze Zustand in der Adresszeile — ein Neuladen behält
 * ihn, ein geteilter Link zeigt beim Empfänger dieselbe Auswahl.
 */
class ReplayIndexRequest extends GlobalFilterRequest
{
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
            'errors' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Nur Sitzungen, in denen etwas schiefgegangen ist.
     *
     * Der einzige Filter dieser Seite, und er ist der einzige, der sich lohnt:
     * eine Aufzeichnung ohne Fehler ist eine Sitzung wie jede andere, und wer
     * ohne einen konkreten Anlass hier landet, sucht die mit dem roten Punkt.
     */
    public function onlyWithErrors(): bool
    {
        return (bool) $this->validated('errors');
    }
}
