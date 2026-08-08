<?php

namespace App\Http\Requests;

use App\Enums\WebVital;
use App\Models\Transaction;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Die Eingabe der Web-Vitals-Detailseite: die globale Filterleiste, die Seite,
 * um die es geht, und der Messwert, den Verlauf und Aufschlüsselung zeigen.
 *
 * Der Seitenname steht als Parameter in der Adresszeile und nicht als
 * Pfad-Abschnitt — aus demselben Grund wie bei der Transaktions-Detailseite: er
 * ist ein Pfad und trägt damit genau die Zeichen, die ein Abschnitt nicht tragen
 * kann.
 */
class WebVitalDetailRequest extends GlobalFilterRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'name' => ['required', 'string', 'max:'.Transaction::NAME_LIMIT],
            'vital' => ['nullable', Rule::in(WebVital::values())],
        ];
    }

    public function name(): string
    {
        return (string) $this->validated('name');
    }

    /**
     * Der gewählte Messwert; ohne Angabe das LCP.
     *
     * Es ist der Kernwert, der am häufigsten das Problem ist und den fast jedes
     * SDK meldet — eine Voreinstellung, die in den meisten Fällen schon die
     * richtige Frage stellt. Ein Messwert, den die Adresszeile nennt, der aber
     * nicht bekannt ist, wird von der Prüfung oben abgewiesen; hier kommt nur
     * an, was es gibt.
     */
    public function vital(): WebVital
    {
        $vital = $this->validated('vital');

        return is_string($vital) ? (WebVital::tryFrom($vital) ?? WebVital::Lcp) : WebVital::Lcp;
    }
}
