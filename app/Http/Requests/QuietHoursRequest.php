<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Ruhezeiten eines Nutzers.
 *
 * `different` auf dem Ende ist keine Förmlichkeit: gleiche Zeiten ergeben
 * keine Spanne, und je nach Auslegung wäre das entweder gar keine oder eine
 * ununterbrochene Ruhezeit. Beides will niemand versehentlich einstellen.
 */
class QuietHoursRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'quiet_hours_enabled' => ['boolean'],
            'quiet_from' => ['required', 'date_format:H:i'],
            'quiet_until' => ['required', 'date_format:H:i', 'different:quiet_from'],
            'timezone' => ['required', 'string', 'timezone'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'quiet_hours_enabled' => 'Ruhezeiten',
            'quiet_from' => 'Beginn',
            'quiet_until' => 'Ende',
            'timezone' => 'Zeitzone',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'quiet_until.different' => 'Beginn und Ende dürfen nicht gleich sein — sonst ergibt sich keine Spanne.',
        ];
    }
}
