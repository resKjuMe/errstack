<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Die Versionsliste als Eingabe: die Felder der globalen Filterleiste, dazu die
 * Seite.
 *
 * Ohne Sortierung — sie steht fest. Eine Versionsliste hat genau eine sinnvolle
 * Ordnung: die neueste zuerst. „Nach Name sortieren" wäre die Ordnung, die diese
 * Aufgabe gerade abgeschafft hat.
 */
class ReleaseListRequest extends GlobalFilterRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
