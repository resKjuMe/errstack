<?php

namespace App\Http\Requests;

use App\Models\SamplingRule;
use App\Models\Transaction;
use App\Support\Ingest\Sampling\Sampler;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Angaben zu einer projektweiten Stichproben-Regel.
 *
 * Zwei Dinge sind hier strenger geprüft als in einem gewöhnlichen Formular, und
 * beide aus demselben Grund: eine Regel wirft Messungen weg, und Weggeworfenes
 * kommt nicht zurück.
 *
 * - **Die Quote muss größer als null sein.** Null wäre „nichts behalten", und
 *   das ist keine Stichprobe, sondern ein Abschalten — dafür gibt es das
 *   Abschalten der Regel. Der Unterschied ist wichtig, weil eine Quote von null
 *   auch keine Hochrechnung erlaubt: aus nichts wird nichts.
 * - **Die Mindestquote ist beschränkt.** Sie ist die Zusage an seltene Vorgänge
 *   und keine zweite Quote. Wer „mindestens tausend je Minute" einträgt, hat die
 *   Stichprobe abgeschafft, ohne es zu bemerken.
 *
 * Was **nicht** geprüft wird: ob die Bedingungen auf irgendetwas zutreffen. Eine
 * Regel für einen Endpunkt, den es noch nicht gibt, ist sinnvoll — sie wird
 * geschrieben, bevor der Verkehr kommt.
 */
class SamplingRuleRequest extends FormRequest
{
    /**
     * Die höchste zulässige Mindestquote je Zeitfenster.
     *
     * Das Fenster ist eine Minute ({@see Transaction::BUCKET_SECONDS}).
     * Tausend garantierte Messungen je Minute und Vorgang sind mehr, als eine
     * Anwendung mit Stichprobe überhaupt behalten will — die Grenze ist der
     * Schutz davor, sich die Quote versehentlich wegzukonfigurieren.
     */
    public const MAX_MINIMUM_PER_WINDOW = 1000;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],

            // Alle Bedingungen sind freiwillig: eine Regel ohne jede Bedingung
            // trifft auf alles zu und ist die Vorgabe des Projekts. Die
            // Längengrenzen sind die der Spalten, gegen die verglichen wird —
            // ein längeres Muster könnte nie zutreffen.
            'transaction_name' => ['nullable', 'string', 'max:200'],
            'environment' => ['nullable', 'string', 'max:64'],
            'release' => ['nullable', 'string', 'max:255'],
            'op' => ['nullable', 'string', 'max:100'],

            // `numeric` und nicht `decimal`: die Quote kommt aus einem Zahlenfeld
            // und darf `0.01` wie `0.010000` sein.
            'sample_rate' => ['required', 'numeric', 'min:'.Sampler::MIN_RATE, 'max:1'],

            'minimum_per_window' => ['sometimes', 'integer', 'min:0', 'max:'.self::MAX_MINIMUM_PER_WINDOW],

            'position' => ['sometimes', 'integer', 'min:0', 'max:'.(SamplingRule::MAX_PER_PROJECT - 1)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Leere Textfelder sind „keine Bedingung" und nicht „die leere Bedingung".
     *
     * Ein HTML-Formular schickt für ein nicht ausgefülltes Feld die leere
     * Zeichenkette. Ohne diese Umwandlung stünde sie in der Spalte, und die Regel
     * sähe aus wie eine mit Bedingung — greifen würde sie trotzdem auf alles,
     * weil die Auswertung leere Muster übergeht. Zwei Schreibweisen für dasselbe
     * sind eine Fehlerquelle, die sich hier abschneiden lässt.
     */
    protected function prepareForValidation(): void
    {
        $blanks = [];

        foreach (SamplingRule::CONDITIONS as $field) {
            $value = $this->input($field);

            if (is_string($value) && trim($value) === '') {
                $blanks[$field] = null;
            }
        }

        if ($blanks !== []) {
            $this->merge($blanks);
        }
    }
}
