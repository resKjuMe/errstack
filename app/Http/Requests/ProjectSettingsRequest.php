<?php

namespace App\Http\Requests;

use App\Enums\Platform;
use App\Enums\ResolutionBehavior;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Einstellungen eines bestehenden Projekts. Der Slug ist bewusst nicht dabei:
 * er bleibt, damit einmal verteilte Links und Zugangsdaten gültig bleiben.
 */
class ProjectSettingsRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'platform' => ['required', Rule::enum(Platform::class)],
            // Die Umgebung ist frei wählbar (production, staging, kunde-a …),
            // muss aber in eine Adresszeile und einen Filter passen.
            'default_environment' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9][a-z0-9._-]*$/'],
            'resolution_behavior' => ['required', Rule::enum(ResolutionBehavior::class)],
            'retention_days' => ['required', 'integer', 'min:1', 'max:365'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'default_environment.regex' => 'Erlaubt sind Kleinbuchstaben, Ziffern, Punkt, Bindestrich und Unterstrich.',
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
            'default_environment' => 'Standard-Umgebung',
            'resolution_behavior' => 'Auflösungs-Verhalten',
            'retention_days' => 'Datenaufbewahrung',
        ];
    }
}
