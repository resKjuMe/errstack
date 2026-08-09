<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Die Datenschutz-Einstellungen eines Projekts: die drei Schalter und die
 * Aufbewahrungsfrist der Sitzungs-Aufzeichnungen.
 *
 * Getrennt von {@see ProjectSettingsRequest}, obwohl es dieselbe Tabelle ist: die
 * Schalter stehen auf einer eigenen Seite und werden für sich gespeichert. In
 * einem Formular mit Name und Plattform würden sie bei jedem Umbenennen
 * mitgeschickt — und ein vergessenes Kontrollfeld schaltete dann still die
 * IP-Speicherung wieder an.
 *
 * Die Frist steht hier und nicht bei den übrigen Projekteinstellungen, weil sie
 * dieselbe Art von Entscheidung ist wie die Schalter daneben: nicht „wie lange
 * brauchen wir die Daten", sondern „wie lange dürfen wir sie haben" (M3).
 */
class ProjectPrivacyRequest extends FormRequest
{
    /**
     * Obergrenze der Aufbewahrungsfrist in Tagen.
     *
     * Neunzig, und das ist keine technische Grenze, sondern eine fachliche: eine
     * Aufzeichnung ist der Bildschirm eines Menschen, und eine Frist, die in
     * Jahren gerechnet wird, ist keine Frist mehr. Wer länger aufheben will, hat
     * eine Frage zu beantworten, die kein Eingabefeld beantworten kann.
     */
    public const MAX_REPLAY_RETENTION_DAYS = 90;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'scrub_ip_addresses' => ['required', 'boolean'],
            'scrub_user_data' => ['required', 'boolean'],
            'scrub_attachments' => ['required', 'boolean'],
            // `nullable` heißt „die Vorgabe des Betreibers", `0` heißt „gar
            // nicht aufzeichnen". Beides sind gültige Antworten, und sie
            // bedeuten Verschiedenes — deshalb ist die Null nicht ausgeschlossen
            // und die leere Angabe nicht verboten.
            'replay_retention_days' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_REPLAY_RETENTION_DAYS],
        ];
    }
}
