<?php

namespace App\Http\Requests;

use App\Enums\ScrubRuleType;
use App\Support\Ingest\Scrubbing\Directive;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Eine eigene Datenschutz-Regel, angelegt oder geändert.
 *
 * Der Ausdruck wird hier mit **derselben** Übersetzung geprüft, die die Aufnahme
 * später benutzt ({@see Directive::compile()}). Eine eigene Prüfung wäre eine
 * zweite Meinung darüber, was ein gültiges Muster ist — und die auseinanderlaufen
 * zu lassen hieße: eine Regel wird angenommen und greift nie, ohne dass es
 * jemandem auffällt.
 */
class ScrubRuleRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(ScrubRuleType::class)],

            'expression' => [
                'required',
                'string',
                'max:500',
                function (string $attribute, mixed $value, callable $fail): void {
                    $type = ScrubRuleType::tryFrom((string) $this->input('type'));

                    if ($type === null || ! is_string($value)) {
                        // Die Art ist schon durch ihre eigene Regel gefallen;
                        // eine zweite Meldung zum selben Fehler hilft niemandem.
                        return;
                    }

                    if (Directive::compile($type, $value) === null) {
                        $fail(__('privacy.validation.expression'));
                    }
                },
            ],

            // Der Abschnitt ist ein Weg im Feld-Baum der Meldung
            // (`request.data`). Punkte trennen, alles andere wäre kein Weg —
            // Leerzeichen und Klammern deuten auf einen Versuch hin, hier ein
            // Muster unterzubringen, und das gehört in den Ausdruck.
            'path' => ['nullable', 'string', 'max:200', 'regex:/^[A-Za-z0-9_.\-]+$/'],

            'is_active' => ['boolean'],
        ];
    }

    /**
     * Ein leeres Feld für den Abschnitt bedeutet „ganze Meldung" und wird zu
     * `null`. Ohne diese Umwandlung stünde eine leere Zeichenkette in der
     * Spalte, und die Auswertung müsste beide Schreibweisen für dasselbe kennen.
     */
    protected function prepareForValidation(): void
    {
        $path = $this->input('path');

        if (is_string($path) && trim($path) === '') {
            $this->merge(['path' => null]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'type' => __('privacy.rules.type'),
            'expression' => __('privacy.rules.expression'),
            'path' => __('privacy.rules.path'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'path.regex' => __('privacy.validation.path'),
        ];
    }
}
