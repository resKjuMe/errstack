<?php

namespace App\Http\Requests;

use App\Enums\InboundFilterKind;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Die sieben Schalter der Eingangsfilter.
 *
 * Alle sieben sind Pflichtangaben, obwohl es Häkchen sind: das Formular schickt
 * sie zusammen, und ein fehlender Wert soll nicht stillschweigend als „aus"
 * durchgehen. Der Unterschied zählt hier mehr als anderswo — ein Schalter, der
 * sich beim Speichern eines anderen von selbst zurückstellt, wäre an einem
 * Filter nicht zu bemerken, solange niemand die Zählung ansieht.
 */
class ProjectFilterRequest extends FormRequest
{
    /**
     * Das Recht prüft der Controller über die Policy; hier gibt es nichts, was
     * darüber hinausginge.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $rules = [];

        foreach (InboundFilterKind::columns() as $column) {
            $rules[$column] = ['required', 'boolean'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = [];

        foreach (InboundFilterKind::cases() as $kind) {
            $attributes[$kind->column()] = $kind->label();
        }

        return $attributes;
    }
}
