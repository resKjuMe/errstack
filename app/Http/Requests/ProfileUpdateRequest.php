<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Support\Locales;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Ein leer gelassenes Auswahlfeld kommt als leerer Text an, nicht als null —
     * ohne diese Umsetzung landete die leere Zeichenkette in der Spalte und
     * wäre eine ungültige Sprache statt „keine eigene Wahl".
     */
    protected function prepareForValidation(): void
    {
        if ($this->input('locale') === '') {
            $this->merge(['locale' => null]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
            // Null heißt „keine eigene Wahl": dann folgt die Anzeige der
            // Spracheinstellung des Browsers (App\Support\Locales).
            'locale' => ['nullable', Rule::in(Locales::SUPPORTED)],
        ];
    }
}
