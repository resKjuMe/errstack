<?php

namespace App\Http\Requests;

use App\Enums\AlertComparison;
use App\Enums\AlertDirection;
use App\Enums\AlertMetric;
use App\Models\MetricAlert;
use App\Models\Transaction;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ein Schwellwert-Alarm als Eingabe.
 *
 * Die Regeln sind mehr als eine Formprüfung: ein Alarm lässt sich auf mehrere
 * Arten so einstellen, dass er **nie** auslöst oder **nie wieder** aufgeht, und
 * beides fällt im Betrieb erst auf, wenn man sich darauf verlassen hat. Deshalb
 * werden hier auch die Zusammenhänge geprüft und nicht nur die Felder.
 */
class MetricAlertRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:'.MetricAlert::NAME_LIMIT],
            'metric' => ['required', Rule::enum(AlertMetric::class)],
            'direction' => ['required', Rule::enum(AlertDirection::class)],
            'comparison' => ['required', Rule::enum(AlertComparison::class)],
            'environment' => ['nullable', 'string', 'max:64'],
            'transaction_name' => ['nullable', 'string', 'max:'.Transaction::NAME_LIMIT],
            'window_minutes' => [
                'required',
                'integer',
                'min:'.MetricAlert::MIN_WINDOW_MINUTES,
                'max:'.MetricAlert::MAX_WINDOW_MINUTES,
            ],
            'warning_threshold' => ['nullable', 'numeric'],
            'critical_threshold' => ['nullable', 'numeric'],
            'resolve_threshold' => ['nullable', 'numeric'],
            'minimum_samples' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Die Prüfungen, die erst im Zusammenspiel Sinn ergeben.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->requireAThreshold($validator);
            $this->orderThresholds($validator);
            $this->rejectTransactionNameOnErrors($validator);
        });
    }

    /**
     * Mindestens eine auslösende Schwelle.
     *
     * Ein Alarm ohne beide wäre eine Regel, die jede Minute rechnet und nie
     * etwas sagt — und in einer Liste aussieht, als überwache sie etwas.
     */
    private function requireAThreshold(Validator $validator): void
    {
        if ($this->input('warning_threshold') === null && $this->input('critical_threshold') === null) {
            $validator->errors()->add('warning_threshold', __('alerts.validation.threshold_required'));
        }
    }

    /**
     * Die Schwellen müssen in der Richtung des Alarms auseinanderliegen.
     *
     * Bei „überschreitet" muss die kritische Schwelle über der Warnschwelle
     * liegen und die Auflösungsschwelle **unter** der niedrigsten auslösenden.
     * Stünde sie darüber, wäre der Alarm in dem Augenblick aufgelöst, in dem er
     * auslöst — die Hysterese liefe verkehrt herum, und das Ergebnis wäre ein
     * Alarm, der abwechselnd meldet und entwarnt.
     */
    private function orderThresholds(Validator $validator): void
    {
        $direction = AlertDirection::tryFrom((string) $this->input('direction'));

        if ($direction === null) {
            return;
        }

        $warning = self::toFloat($this->input('warning_threshold'));
        $critical = self::toFloat($this->input('critical_threshold'));
        $resolve = self::toFloat($this->input('resolve_threshold'));

        $ascending = $direction === AlertDirection::Above;

        if ($warning !== null && $critical !== null && ! self::ordered($warning, $critical, $ascending)) {
            $validator->errors()->add('critical_threshold', __(
                $ascending ? 'alerts.validation.critical_above' : 'alerts.validation.critical_below',
            ));
        }

        if ($resolve === null) {
            return;
        }

        $lowest = $warning ?? $critical;

        if ($lowest !== null && ! self::ordered($resolve, $lowest, $ascending)) {
            $validator->errors()->add('resolve_threshold', __(
                $ascending ? 'alerts.validation.resolve_below' : 'alerts.validation.resolve_above',
            ));
        }
    }

    /**
     * Ein Vorgangsname ergibt nur bei den Antwortzeit-Kennzahlen einen Sinn —
     * eine Fehlermeldung trägt keinen.
     *
     * Stillschweigend zu übergehen wäre die schlechtere Wahl: die Einschränkung
     * stünde dann im Formular und wirkte nicht, und der Alarm zählte alle Fehler
     * statt der eines Vorgangs.
     */
    private function rejectTransactionNameOnErrors(Validator $validator): void
    {
        $metric = AlertMetric::tryFrom((string) $this->input('metric'));

        if ($metric === null || $metric->isTransactionMetric()) {
            return;
        }

        if ($this->filled('transaction_name')) {
            $validator->errors()->add('transaction_name', __('alerts.validation.transaction_not_supported'));
        }
    }

    /**
     * Die Werte, wie das Modell sie erwartet.
     *
     * `minimum_samples` und `is_active` bekommen hier ihre Vorgabe, damit das
     * Formular sie weglassen darf: ein Alarm ohne Angabe ist aktiv, und ohne
     * Mindestzahl gilt jede Messung.
     *
     * @return array<string, mixed>
     */
    public function values(): array
    {
        $values = $this->validated();

        $values['minimum_samples'] = (int) ($values['minimum_samples'] ?? 0);
        $values['is_active'] = (bool) ($values['is_active'] ?? true);

        foreach (['environment', 'transaction_name'] as $optional) {
            if (($values[$optional] ?? '') === '') {
                $values[$optional] = null;
            }
        }

        return $values;
    }

    private static function toFloat(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }

    private static function ordered(float $lower, float $higher, bool $ascending): bool
    {
        return $ascending ? $lower < $higher : $lower > $higher;
    }
}
