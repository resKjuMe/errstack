<?php

namespace App\Http\Requests;

use App\Enums\QuotaCategory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Die Kontingente einer Ebene, alle Datenarten in einem Formular.
 *
 * Ein Formular und nicht fünf: die Datenarten teilen sich einen Vorrat, und wer
 * die Fehler von 100.000 auf 200.000 hebt, will im selben Blick sehen, was das
 * für die Transaktionen bedeutet. Fünf Formulare hießen fünfmal Speichern und
 * fünf Zwischenzustände, von denen keiner die Absicht abbildet.
 */
class QuotaRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'quotas' => ['present', 'array'],
            'quotas.*' => ['array'],
            // Die Obergrenzen halten Tippfehler heraus, die sonst als
            // „unbegrenzt" durchgingen: eine 1 zu viel, und die Grenze greift
            // erst nach der Lebenszeit der Installation.
            'quotas.*.per_month' => ['nullable', 'integer', 'min:1', 'max:1000000000'],
            'quotas.*.per_minute' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ];
    }

    /**
     * Die geprüften Kontingente, nach Datenart.
     *
     * Unbekannte Datenarten sind hier schon heraus — sie fallen in
     * {@see prepareForValidation()} weg, wo auch die leeren Felder zu „null"
     * werden.
     *
     * @return array<string, array{per_month: int|null, per_minute: int|null}>
     */
    public function quotas(): array
    {
        /** @var array<string, array{per_month: mixed, per_minute: mixed}> $quotas */
        $quotas = $this->validated('quotas') ?? [];

        $result = [];

        foreach ($quotas as $category => $values) {
            $result[$category] = [
                'per_month' => $values['per_month'] === null ? null : (int) $values['per_month'],
                'per_minute' => $values['per_minute'] === null ? null : (int) $values['per_minute'],
            ];
        }

        return $result;
    }

    /**
     * Leere Felder aus dem Formular meinen „unbegrenzt" — ohne diese Umsetzung
     * käme der leere String an und scheiterte an `integer`.
     */
    protected function prepareForValidation(): void
    {
        $quotas = $this->input('quotas');

        if (! is_array($quotas)) {
            return;
        }

        $clean = [];

        foreach ($quotas as $category => $values) {
            if (! is_string($category) || QuotaCategory::tryFrom($category) === null || ! is_array($values)) {
                continue;
            }

            $clean[$category] = [
                'per_month' => self::emptyToNull($values['per_month'] ?? null),
                'per_minute' => self::emptyToNull($values['per_minute'] ?? null),
            ];
        }

        $this->merge(['quotas' => $clean]);
    }

    private static function emptyToNull(mixed $value): mixed
    {
        return $value === '' || $value === null ? null : $value;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = [];

        foreach (QuotaCategory::cases() as $category) {
            $attributes['quotas.'.$category->value.'.per_month'] = __('quotas.settings.per_month').' ('.$category->label().')';
            $attributes['quotas.'.$category->value.'.per_minute'] = __('quotas.settings.per_minute').' ('.$category->label().')';
        }

        return $attributes;
    }
}
