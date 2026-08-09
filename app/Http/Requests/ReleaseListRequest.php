<?php

namespace App\Http\Requests;

use App\Enums\ReleaseSort;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Die Versionsliste als Eingabe: die Felder der globalen Filterleiste, dazu
 * Sortierung und Seite.
 *
 * Die Sortierung kam mit R8 dazu und ist keine Meinungsänderung: bis dahin hatte
 * die Liste genau eine sinnvolle Ordnung — die neueste zuerst —, und „nach Name
 * sortieren" wäre die Ordnung gewesen, die R1 gerade abgeschafft hatte. Mit der
 * Gesundheit steht eine zweite Frage in der Liste („welche Auslieferung ist die
 * schlechteste?"), und die lässt sich nur über die Reihenfolge beantworten.
 */
class ReleaseListRequest extends GlobalFilterRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'sort' => ['nullable', Rule::enum(ReleaseSort::class)],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function sort(): ReleaseSort
    {
        return ReleaseSort::tryFrom((string) $this->validated('sort')) ?? ReleaseSort::default();
    }
}
