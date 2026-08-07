<?php

namespace App\Http\Requests;

use App\Enums\FilterPeriod;
use App\Models\Environment;
use App\Support\Filters\GlobalFilter;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Die globale Filterleiste als Eingabe: dieselben Felder für jede
 * Auswertungsseite. Der Zustand steht vollständig in der Adresszeile, damit ein
 * Neuladen ihn behält und ein geteilter Link dieselbe Auswahl zeigt.
 *
 * Geprüft wird nur die Form; welche Projekte und Umgebungen es gibt, entscheidet
 * {@see GlobalFilter} — ein Link auf ein gelöschtes Projekt soll die Seite nicht
 * mit einem Fehler beantworten, sondern ohne diese Einschränkung zeigen.
 */
class GlobalFilterRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'projects' => ['nullable', 'array'],
            'projects.*' => ['string', 'max:255'],
            'environment' => ['nullable', 'string', 'max:'.Environment::NAME_LIMIT],
            'period' => ['nullable', Rule::enum(FilterPeriod::class)],
            'from' => ['nullable', 'date', 'required_if:period,custom'],
            'to' => ['nullable', 'date', 'after_or_equal:from', 'required_if:period,custom'],
            'tz' => ['nullable', 'string', 'timezone'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'to.after_or_equal' => 'Das Ende des Zeitraums liegt vor seinem Anfang.',
            'from.required_if' => 'Für einen eigenen Zeitraum fehlt der Anfang.',
            'to.required_if' => 'Für einen eigenen Zeitraum fehlt das Ende.',
        ];
    }

    /**
     * Der aufgelöste Filter für die aktive Organisation des Betrachters.
     */
    public function filter(): GlobalFilter
    {
        $user = $this->user();
        $projects = $this->validated('projects');

        return GlobalFilter::resolve($user->resolveCurrentOrganization(), $user, [
            'projects' => is_array($projects) ? array_values($projects) : [],
            'environment' => $this->stringOrNull($this->validated('environment')),
            'period' => $this->stringOrNull($this->validated('period')),
            'from' => $this->stringOrNull($this->validated('from')),
            'to' => $this->stringOrNull($this->validated('to')),
            'tz' => $this->stringOrNull($this->validated('tz')),
        ]);
    }

    private function stringOrNull(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}
