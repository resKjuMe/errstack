<?php

namespace App\Http\Requests;

use App\Enums\AuditAction;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Filter des Änderungsprotokolls — dieselben Werte für die Ansicht wie für den
 * Export, damit „exportieren" genau das ausgibt, was gerade auf dem Bildschirm
 * steht. Die Rechteprüfung übernimmt die OrganizationPolicy im Controller.
 */
class AuditLogFilterRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'actor' => ['nullable', 'integer'],
            'action' => ['nullable', Rule::enum(AuditAction::class)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'to.after_or_equal' => 'Das Ende des Zeitraums liegt vor seinem Anfang.',
        ];
    }

    /**
     * Die geprüften Werte in der Form, die Abfrage und Oberfläche brauchen:
     * leere Felder als `null`, der Zeitraum als Zeitpunkte. Das Ende gilt
     * einschließlich — wer den 5. wählt, will den 5. dabeihaben.
     *
     * @return array{actor: int|null, action: AuditAction|null, from: Carbon|null, to: Carbon|null}
     */
    public function filters(): array
    {
        $action = $this->validated('action');
        $from = $this->validated('from');
        $to = $this->validated('to');

        return [
            'actor' => $this->integerOrNull($this->validated('actor')),
            'action' => $action === null ? null : AuditAction::from((string) $action),
            'from' => $from === null ? null : Carbon::parse((string) $from)->startOfDay(),
            'to' => $to === null ? null : Carbon::parse((string) $to)->endOfDay(),
        ];
    }

    /**
     * Die Werte so, wie sie in den Formularfeldern stehen — leere Felder als
     * leerer Text, damit die Oberfläche sie unverändert zurückspielen kann.
     *
     * @return array{actor: string, action: string, from: string, to: string}
     */
    public function formValues(): array
    {
        return [
            'actor' => (string) ($this->validated('actor') ?? ''),
            'action' => (string) ($this->validated('action') ?? ''),
            'from' => (string) ($this->validated('from') ?? ''),
            'to' => (string) ($this->validated('to') ?? ''),
        ];
    }

    private function integerOrNull(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
