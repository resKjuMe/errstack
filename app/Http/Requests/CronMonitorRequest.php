<?php

namespace App\Http\Requests;

use App\Enums\CronIntervalUnit;
use App\Enums\CronScheduleType;
use App\Support\Crons\CronSchedule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Angaben zu einem überwachten Cronjob.
 *
 * Die Kennung (`slug`) ist bewusst nicht dabei: sie entsteht aus dem Namen und
 * steht danach im Code des Jobs. Ließe man sie ändern, hörten die Check-ins
 * eines laufenden Jobs von einer Umbenennung an auf anzukommen — und zwar
 * unbemerkt, denn ein Monitor ohne Check-ins sieht genauso aus wie ein Job, der
 * nicht läuft.
 */
class CronMonitorRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],

            'schedule_type' => ['required', Rule::enum(CronScheduleType::class)],

            // Je nach Form ist das eine oder das andere Feld Pflicht — ein
            // Monitor ohne Zeitplan könnte nie feststellen, dass eine
            // Ausführung ausgeblieben ist, und wäre damit wertlos.
            'schedule_expression' => [
                Rule::requiredIf(fn (): bool => $this->input('schedule_type') === CronScheduleType::Crontab->value),
                'nullable',
                'string',
                'max:128',
                function (string $attribute, mixed $value, callable $fail): void {
                    if (is_string($value) && $value !== '' && ! CronSchedule::isValidExpression($value)) {
                        $fail(__('crons.validation.expression'));
                    }
                },
            ],

            'interval_value' => [
                Rule::requiredIf(fn (): bool => $this->input('schedule_type') === CronScheduleType::Interval->value),
                'nullable',
                'integer',
                'min:1',
                'max:1000',
            ],
            'interval_unit' => [
                Rule::requiredIf(fn (): bool => $this->input('schedule_type') === CronScheduleType::Interval->value),
                'nullable',
                Rule::enum(CronIntervalUnit::class),
            ],

            'timezone' => ['required', 'string', 'max:64', 'timezone'],

            // Null Minuten Toleranz sind zulässig und heißen „auf die Minute";
            // die Obergrenze von einem Tag hält Eingaben heraus, die die
            // Überwachung faktisch abschalten würden.
            'checkin_margin_minutes' => ['required', 'integer', 'min:0', 'max:1440'],

            // Mindestens eine Minute: eine Laufzeitgrenze von null würde jede
            // begonnene Ausführung sofort als hängend melden.
            'max_runtime_minutes' => ['required', 'integer', 'min:1', 'max:10080'],

            'failure_tolerance' => ['required', 'integer', 'min:1', 'max:100'],
            'recovery_tolerance' => ['required', 'integer', 'min:1', 'max:100'],

            'is_active' => ['boolean'],
        ];
    }

    /**
     * Die Felder der jeweils **anderen** Zeitplan-Form werden geleert.
     *
     * Ohne das bliebe der alte Cron-Ausdruck stehen, wenn jemand von „täglich
     * 02:00" auf „alle 15 Minuten" wechselt — und beim Zurückwechseln stünde
     * plötzlich wieder ein Zeitplan da, den niemand mehr erwartet.
     */
    protected function prepareForValidation(): void
    {
        $type = $this->input('schedule_type');

        if ($type === CronScheduleType::Crontab->value) {
            $this->merge(['interval_value' => null, 'interval_unit' => null]);
        }

        if ($type === CronScheduleType::Interval->value) {
            $this->merge(['schedule_expression' => null]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => __('crons.name'),
            'schedule_type' => __('crons.schedule_type'),
            'schedule_expression' => __('crons.expression'),
            'interval_value' => __('crons.interval_value'),
            'interval_unit' => __('crons.interval_unit'),
            'timezone' => __('crons.timezone'),
            'checkin_margin_minutes' => __('crons.margin'),
            'max_runtime_minutes' => __('crons.max_runtime'),
            'failure_tolerance' => __('crons.failure_tolerance'),
            'recovery_tolerance' => __('crons.recovery_tolerance'),
        ];
    }
}
