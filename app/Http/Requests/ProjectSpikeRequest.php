<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Die Einstellungen des Ausschlag-Schutzes eines Projekts (A7).
 *
 * Der Faktor beginnt bei 1,5 und nicht bei 1: bei genau 1 wäre die Schwelle der
 * Vergleichswert selbst, und der wird definitionsgemäß in etwa der Hälfte aller
 * Minuten überschritten — das Projekt läge dauerhaft in der Drosselung.
 *
 * Die Untergrenze darf nicht null sein. Sie ist der Schutz des Schutzes: ohne
 * sie genügt bei einem ruhigen Projekt ein einziger Ausschlag von drei
 * Meldungen, um die Aufnahme zu drosseln.
 */
class ProjectSpikeRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'spike_protection_enabled' => ['required', 'boolean'],
            'spike_threshold_factor' => ['required', 'numeric', 'min:1.5', 'max:100'],
            'spike_minimum_events' => ['required', 'integer', 'min:10', 'max:10000000'],
            // Null ist erlaubt und heißt „keine Ruhefrist": wer von Hand
            // aufhebt, nimmt dann in Kauf, dass gleich wieder gedrosselt wird.
            // Das ist eine Entscheidung und keine Lücke in der Prüfung.
            'spike_release_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'spike_protection_enabled' => 'Ausschlag-Schutz',
            'spike_threshold_factor' => 'Faktor',
            'spike_minimum_events' => 'Untergrenze',
            'spike_release_minutes' => 'Ruhefrist',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'spike_threshold_factor.min' => 'Der Faktor muss über 1 liegen — bei genau 1 wäre die Hälfte aller Minuten eine Spitze.',
        ];
    }
}
