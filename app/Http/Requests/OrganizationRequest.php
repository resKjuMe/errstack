<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Anlegen und Umbenennen einer Organisation — beides braucht nur den Namen.
 * Die Rechteprüfung übernimmt die OrganizationPolicy im Controller.
 */
class OrganizationRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
