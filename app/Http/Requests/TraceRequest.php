<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Die Eingabe der Trace-Ansicht: welcher Schritt geöffnet ist.
 *
 * Mehr steht nicht in der Adresszeile, und mehr braucht die Seite auch nicht —
 * die Spur selbst ist der Pfad. Der geöffnete Schritt gehört trotzdem dorthin
 * und nicht in den Zustand der Oberfläche: „schau dir diese Abfrage an" ist die
 * häufigste Verwendung dieser Seite, und sie ist nur dann ein Link, wenn die
 * Auswahl in der Adresse steht.
 */
class TraceRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Nur eine Obergrenze, keine Form: was hier steht, wird nicht
            // geglaubt, sondern in {@see span()} auf das eingedampft, was eine
            // Span-Kennung sein kann. Eine strenge Regel würde einen
            // verunglückten Link mit einer Fehlermeldung beantworten statt mit
            // der Spur, die er meint — dieselbe Entscheidung wie in
            // {@see GlobalFilterRequest}.
            'schritt' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * Der geöffnete Schritt, klein geschrieben wie in der Ablage.
     *
     * Span-Kennungen sind 16 Hex-Zeichen. Was das nicht ist, gilt als „kein
     * Schritt gewählt": ein abgeschnittener oder verstümmelter Link zeigt dann
     * die Spur ohne geöffneten Schritt.
     */
    public function span(): ?string
    {
        $span = $this->validated('schritt');

        if (! is_string($span) || preg_match('/^[0-9a-fA-F]{1,16}$/', $span) !== 1) {
            return null;
        }

        return strtolower($span);
    }
}
