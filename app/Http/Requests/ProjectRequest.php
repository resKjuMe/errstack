<?php

namespace App\Http\Requests;

use App\Enums\Platform;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Anlegen eines Projekts: Name und Plattform genügen, alles Weitere hat einen
 * brauchbaren Standardwert und steht danach in den Einstellungen. Die
 * Rechteprüfung übernimmt die OrganizationPolicy im Controller.
 */
class ProjectRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'platform' => ['required', Rule::enum(Platform::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'Name',
            'platform' => 'Plattform',
        ];
    }
}
