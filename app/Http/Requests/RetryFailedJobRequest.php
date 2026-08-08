<?php

namespace App\Http\Requests;

use App\Support\Operations\FailedJobs;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Die Kennung eines gescheiterten Jobs — für „erneut starten" und „wegwerfen"
 * dieselbe.
 *
 * Geprüft wird nur die Form, nicht das Vorhandensein: die Fehlerablage ist
 * keine Tabelle, die sich per Regel abfragen ließe (sie kann auch DynamoDB
 * sein), und wer eine Ansicht offen hat, während jemand anderes aufräumt, soll
 * eine ehrliche Auskunft bekommen statt eines Validierungsfehlers. Diesen Fall
 * beantwortet {@see FailedJobs::retry()}.
 *
 * Die Rechteprüfung steht im Controller (Gate `operations`), wie überall in
 * dieser Anwendung.
 */
class RetryFailedJobRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'id' => ['required', 'string', 'max:255'],
        ];
    }

    public function jobId(): string
    {
        return (string) $this->validated('id');
    }
}
