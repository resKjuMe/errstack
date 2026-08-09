<?php

namespace App\Http\Requests;

use App\Models\Dashboard;
use App\Support\CurrentOrganization;
use App\Support\Dashboards\DashboardTemplates;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ein Dashboard als Eingabe: Name, Beschreibung, Freigabe — und beim Anlegen die
 * Vorlage, aus der es entstehen soll.
 *
 * Der Zeitraum fehlt hier, und das ist kein Versehen: er gehört der
 * Filterleiste. Die Begründung steht bei {@see Dashboard}.
 */
class DashboardRequest extends FormRequest
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
                'max:'.Dashboard::NAME_LIMIT,
                // Zweimal derselbe Name im eigenen Bestand wäre in der Liste
                // zweimal dasselbe Wort — und welches der beiden man gerade
                // öffnet, sähe man nicht.
                Rule::unique('dashboards', 'name')
                    ->where('organization_id', $this->organizationId())
                    ->where('user_id', $this->user()->id)
                    ->ignore($this->route('dashboard')),
            ],
            'description' => ['nullable', 'string', 'max:'.Dashboard::DESCRIPTION_LIMIT],
            'shared' => ['nullable', 'boolean'],
            'template' => ['nullable', 'string', Rule::in(array_keys(DashboardTemplates::all()))],
        ];
    }

    protected function prepareForValidation(): void
    {
        $name = $this->input('name');

        if (is_string($name)) {
            $this->merge(['name' => trim($name)]);
        }
    }

    public function name(): string
    {
        return trim((string) $this->validated('name'));
    }

    public function description(): string
    {
        return trim((string) ($this->validated('description') ?? ''));
    }

    public function shared(): bool
    {
        return (bool) $this->validated('shared');
    }

    /**
     * Die Vorlage — nur beim Anlegen von Bedeutung. Ohne sie entsteht ein leeres
     * Dashboard, das man selbst füllt.
     */
    public function template(): ?string
    {
        $template = $this->validated('template');

        return is_string($template) && $template !== '' ? $template : null;
    }

    /**
     * Die Organisation, in der der Name eindeutig sein muss.
     *
     * Beim Ändern die des Dashboards und nicht die aus der Adresse: wer die
     * Organisation gewechselt hat und einen alten Reiter absendet, soll sein
     * Dashboard nicht in eine andere schieben.
     */
    private function organizationId(): ?int
    {
        $dashboard = $this->route('dashboard');

        if ($dashboard instanceof Dashboard) {
            return $dashboard->organization_id;
        }

        return CurrentOrganization::for($this)?->id;
    }
}
