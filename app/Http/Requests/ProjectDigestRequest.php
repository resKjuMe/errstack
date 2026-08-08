<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Die Bündelung eines Projekts (A6).
 *
 * Die Höchstzahl muss über der Mindestzahl liegen, sonst wäre der Korb voll,
 * bevor sich das Bündeln lohnt — und jede Sammelnachricht ginge sofort als
 * Einzelmeldung hinaus. Die Mindestzahl beginnt bei zwei: eine
 * „Sammelnachricht" mit einem einzigen Eintrag ist eine Meldung mit Umweg.
 *
 * Das Fenster darf null sein — das ist das Abschalten und keine Lücke in der
 * Prüfung.
 */
class ProjectDigestRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'digest_window_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'digest_min_events' => ['required', 'integer', 'min:2', 'max:100'],
            'digest_max_events' => ['required', 'integer', 'min:2', 'max:200', 'gt:digest_min_events'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'digest_window_minutes' => 'Zeitfenster',
            'digest_min_events' => 'Mindestanzahl',
            'digest_max_events' => 'Höchstanzahl',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'digest_max_events.gt' => 'Die Höchstanzahl muss über der Mindestanzahl liegen — sonst kommt nie eine Sammelnachricht zustande.',
        ];
    }
}
