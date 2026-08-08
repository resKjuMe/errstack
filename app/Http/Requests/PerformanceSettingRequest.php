<?php

namespace App\Http\Requests;

use App\Enums\PerformanceProblem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Die eingestellten Schwellen eines Projekts.
 *
 * **Die Prüfregeln entstehen aus den Mustern selbst**, nicht aus einer zweiten
 * Liste daneben ({@see PerformanceProblem::limits()}). Der Unterschied fällt
 * beim nächsten neuen Muster auf: eine hier abgeschriebene Liste wäre dann
 * unvollständig, und die neuen Felder gingen still verloren — das Formular
 * schickt sie, die Prüfung kennt sie nicht, `validated()` lässt sie weg. Ein
 * Fehler, der keine Fehlermeldung erzeugt, sondern nur eine Einstellung, die
 * sich nicht speichern lässt.
 */
class PerformanceSettingRequest extends FormRequest
{
    /**
     * @return array<string, list<ValidationRule|string>>
     */
    public function rules(): array
    {
        $rules = [
            'problems' => ['required', 'array'],
        ];

        foreach (PerformanceProblem::cases() as $problem) {
            $rules['problems.'.$problem->value] = ['required', 'array'];
            $rules['problems.'.$problem->value.'.enabled'] = ['required', 'boolean'];

            foreach ($problem->limits() as $key => $limit) {
                $rules['problems.'.$problem->value.'.thresholds.'.$key] = [
                    'required',
                    'integer',
                    'min:'.$limit['min'],
                    'max:'.$limit['max'],
                ];
            }
        }

        return $rules;
    }

    /**
     * Die Einstellungen je Muster, in der Form, in der sie abgelegt werden.
     *
     * @return array<string, array{enabled: bool, thresholds: array<string, int>}>
     */
    public function settings(): array
    {
        /** @var array<string, array<string, mixed>> $input */
        $input = $this->validated('problems');

        $settings = [];

        foreach (PerformanceProblem::cases() as $problem) {
            $values = $input[$problem->value] ?? null;

            if (! is_array($values)) {
                continue;
            }

            $thresholds = [];

            foreach (array_keys($problem->limits()) as $key) {
                $thresholds[$key] = (int) ($values['thresholds'][$key] ?? $problem->defaults()[$key]);
            }

            $settings[$problem->value] = [
                'enabled' => (bool) $values['enabled'],
                'thresholds' => $thresholds,
            ];
        }

        return $settings;
    }
}
