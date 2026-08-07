<?php

namespace App\Http\Requests;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Einladung an eine E-Mail-Adresse. Dass niemand doppelt eingeladen wird und
 * dass die Adresse nicht schon Mitglied ist, gehört zur Eingabeprüfung — die
 * Rechtefrage klärt die OrganizationPolicy.
 */
class StoreInvitationRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique('organization_invitations')
                    ->where('organization_id', $this->organization()->id),
            ],
            'role' => ['required', Rule::enum(OrganizationRole::class)],
        ];
    }

    /**
     * @return list<Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('email')) {
                    return;
                }

                $invited = User::query()->where('email', (string) $this->input('email'))->first();

                if ($invited !== null && $this->organization()->hasMember($invited)) {
                    $validator->errors()->add('email', 'Diese Adresse gehört bereits zur Organisation.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'Für diese Adresse ist bereits eine Einladung offen.',
        ];
    }

    private function organization(): Organization
    {
        $organization = $this->route('organization');

        assert($organization instanceof Organization);

        return $organization;
    }
}
