<?php

namespace App\Http\Requests;

use App\Enums\ApiScope;
use App\Enums\ApiTokenKind;
use App\Models\ApiToken;
use App\Models\Organization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Anlegen eines API-Tokens.
 *
 * Die Rechteprüfung steht hier und nicht nur im Controller, weil sie von den
 * eingegebenen Werten abhängt: welche Art Token und welche Geltungsbereiche
 * jemand vergeben darf, hängt an seiner Rolle. Geprüft wird das als
 * Validierungsfehler am Feld `scopes` — so landet die Auskunft am Formularfeld
 * und nicht auf einer Fehlerseite.
 */
class StoreApiTokenRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                // Der Name ist die Wiedererkennung in der Liste — innerhalb einer
                // Organisation muss er eindeutig sein.
                Rule::unique(ApiToken::class, 'name')
                    ->where('organization_id', $this->organization()->id),
            ],
            'kind' => ['required', Rule::enum(ApiTokenKind::class)],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => [Rule::in(ApiScope::values())],
            // Ohne Angabe gilt das Token unbefristet. Das ist für Server-zu-
            // Server-Zugänge der Normalfall; wer es kürzer will, wählt eine Frist.
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'Name',
            'kind' => 'Art',
            'scopes' => 'Geltungsbereiche',
            'expires_in_days' => 'Gültigkeit',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $user = $this->user();

            if ($user === null) {
                return;
            }

            $organization = $this->organization();

            if ($this->kind() === ApiTokenKind::Organization
                && $user->cannot('createShared', [ApiToken::class, $organization])) {
                $validator->errors()->add(
                    'kind',
                    'Organisationsweite Tokens darf nur die Verwaltung anlegen.',
                );
            }

            foreach ($this->scopes() as $scope) {
                if ($user->cannot('grantScope', [ApiToken::class, $organization, $scope])) {
                    $validator->errors()->add(
                        'scopes',
                        "Die eigene Rolle erlaubt es nicht, „{$scope->label()}“ zu vergeben.",
                    );
                }
            }
        });
    }

    public function kind(): ApiTokenKind
    {
        return ApiTokenKind::from((string) $this->input('kind'));
    }

    /**
     * @return list<ApiScope>
     */
    public function scopes(): array
    {
        $input = $this->input('scopes');

        if (! is_array($input)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (mixed $value): ?ApiScope => is_string($value) ? ApiScope::tryFrom($value) : null,
            $input,
        )));
    }

    /**
     * Ablaufzeitpunkt aus der gewählten Zahl von Tagen — oder null für
     * unbefristet.
     */
    public function expiresAt(): ?Carbon
    {
        $days = $this->input('expires_in_days');

        return is_numeric($days) ? Carbon::now()->addDays((int) $days) : null;
    }

    /**
     * Die Organisation, für die das Token gilt: immer die gerade aktive. Der
     * Controller stellt sicher, dass es eine gibt.
     */
    public function organization(): Organization
    {
        $organization = $this->user()?->resolveCurrentOrganization();

        if ($organization === null) {
            throw new AuthorizationException('Für API-Tokens braucht es zuerst eine Organisation.');
        }

        return $organization;
    }
}
