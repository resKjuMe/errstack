<?php

namespace App\Http\Requests;

use App\Models\Organization;
use App\Models\Team;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Anlegen und Umbenennen eines Teams. Der Name muss innerhalb der Organisation
 * eindeutig sein — über Organisationen hinweg darf er sich wiederholen.
 */
class TeamRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $team = $this->route('team');
        $organization = $team instanceof Team ? $team->organization : $this->route('organization');

        assert($organization instanceof Organization);

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('teams')
                    ->where('organization_id', $organization->id)
                    ->ignore($team instanceof Team ? $team->id : null),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'Ein Team dieses Namens gibt es hier bereits.',
        ];
    }
}
