<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Die drei Datenschutz-Schalter eines Projekts.
 *
 * Getrennt von {@see ProjectSettingsRequest}, obwohl es dieselbe Tabelle ist: die
 * Schalter stehen auf einer eigenen Seite und werden für sich gespeichert. In
 * einem Formular mit Name und Plattform würden sie bei jedem Umbenennen
 * mitgeschickt — und ein vergessenes Kontrollfeld schaltete dann still die
 * IP-Speicherung wieder an.
 */
class ProjectPrivacyRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'scrub_ip_addresses' => ['required', 'boolean'],
            'scrub_user_data' => ['required', 'boolean'],
            'scrub_attachments' => ['required', 'boolean'],
        ];
    }
}
