<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Angaben zu einem Client-Schlüssel. Der öffentliche Teil ist bewusst nicht
 * dabei: er wird erzeugt, nicht eingegeben.
 */
class ProjectKeyRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // Ohne Kontingent gilt der Schlüssel als unbegrenzt; die Obergrenze
            // hält Tippfehler heraus, die als „unbegrenzt" durchgehen würden.
            'rate_limit_per_minute' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ];
    }

    /**
     * Ein leeres Feld aus dem Formular meint „unbegrenzt" — ohne diese
     * Umsetzung käme der leere String an und scheiterte an `integer`.
     */
    protected function prepareForValidation(): void
    {
        if ($this->input('rate_limit_per_minute') === '') {
            $this->merge(['rate_limit_per_minute' => null]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'Name',
            'rate_limit_per_minute' => 'Kontingent',
        ];
    }
}
