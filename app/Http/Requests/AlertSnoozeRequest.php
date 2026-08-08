<?php

namespace App\Http\Requests;

use App\Enums\AlertSnoozeScope;
use App\Models\AlertSnooze;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Eine Stummschaltung als Eingabe: für wen und wie lange.
 *
 * Die Dauer ist eine **Auswahl** und keine freie Zahl. Das ist Absicht: eine
 * Stummschaltung ist der Griff, mit dem eine Überwachung leiser gedreht wird,
 * und ein Eingabefeld lädt zu „99999" ein. Die angebotenen Dauern stehen an der
 * Regel selbst ({@see AlertSnooze::DURATIONS}), damit Auswahlfeld und Prüfung
 * dieselbe Liste benutzen.
 *
 * Beim Aufheben wird nur der Geltungsbereich gebraucht — welche der beiden
 * Stummschaltungen genommen werden soll. Deshalb ist die Dauer nur beim Setzen
 * verlangt.
 */
class AlertSnoozeRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'scope' => ['required', Rule::enum(AlertSnoozeScope::class)],
            'minutes' => [
                Rule::requiredIf(fn (): bool => $this->isMethod('post')),
                'integer',
                Rule::in(AlertSnooze::DURATIONS),
            ],
        ];
    }

    public function scope(): AlertSnoozeScope
    {
        return AlertSnoozeScope::from((string) $this->input('scope'));
    }

    public function minutes(): int
    {
        return (int) $this->input('minutes');
    }
}
