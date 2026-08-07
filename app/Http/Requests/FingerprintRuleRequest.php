<?php

namespace App\Http\Requests;

use App\Models\FingerprintRule;
use App\Support\Ingest\Grouping\FingerprintTemplate;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Angaben zu einer projektweiten Fingerprint-Regel.
 *
 * Die Prüfung ist strenger, als es für ein Formular üblich wäre, und zwar aus
 * einem Grund: eine Regel greift bei **jeder** künftigen Meldung des Projekts,
 * und zwar im Hintergrund. Ein Fehler darin fällt nicht beim Speichern auf,
 * sondern Stunden später an einer Fehlerliste, die nicht mehr stimmt — oder gar
 * nicht, weil ein zu grobes Grouping nichts kaputt macht, sondern nur verbirgt.
 *
 * Zwei Angaben sind deshalb Pflicht und nicht bloß empfohlen:
 *
 * - **Mindestens eine Bedingung.** Eine Regel ohne Bedingung trifft auf alles
 *   zu und zieht das ganze Projekt in eine Gruppe.
 * - **Mindestens ein Bestandteil, der nicht `{{ default }}` ist.** Eine Regel,
 *   die nur das Standardverfahren wiederholt, tut nichts — sieht aber so aus,
 *   als täte sie etwas, und das ist der teurere Zustand.
 */
class FingerprintRuleRequest extends FormRequest
{
    /**
     * Die Felder, auf die sich eine Bedingung beziehen darf.
     *
     * Eine geschlossene Liste und keine freie Eingabe: ein Tippfehler
     * (`error.typ`) ergäbe sonst eine Bedingung, die niemals zutrifft, und eine
     * Regel, die nie greift, sieht genauso aus wie eine, die richtig ist.
     *
     * Die Namen entsprechen App\Support\Ingest\Grouping\Attributes und folgen
     * Sentry, damit ein bestehendes Regelwerk übernommen werden kann.
     *
     * @var list<string>
     */
    public const ATTRIBUTES = [
        'error.type',
        'error.value',
        'error.module',
        'error.mechanism',
        'message',
        'level',
        'platform',
        'title',
        'culprit',
        'transaction',
        'logger',
        'environment',
        'release',
        'dist',
        'server_name',
        'sdk.name',
        'stack.function',
        'stack.module',
        'stack.package',
        'stack.filename',
        'stack.abs_path',
        'stack.path',
    ];

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],

            'matchers' => ['required', 'array', 'min:1', 'max:10'],
            'matchers.*.attribute' => ['required', 'string', $this->attributeRule()],
            'matchers.*.pattern' => ['required', 'string', 'max:400'],
            'matchers.*.negated' => ['sometimes', 'boolean'],

            'fingerprint' => ['required', 'array', 'min:1', 'max:10'],
            'fingerprint.*' => ['required', 'string', 'max:400'],

            'position' => ['sometimes', 'integer', 'min:0', 'max:'.(FingerprintRule::MAX_PER_PROJECT - 1)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Was sich erst am ganzen Formular prüfen lässt.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $fingerprint = $this->input('fingerprint');

            if (! is_array($fingerprint)) {
                return;
            }

            foreach ($fingerprint as $value) {
                if (is_string($value) && ! FingerprintTemplate::isDefault($value)) {
                    return;
                }
            }

            $validator->errors()->add('fingerprint', __('grouping.validation.only_default'));
        });
    }

    /**
     * Ein erlaubtes Feld — Marken zusätzlich in beliebiger Ausprägung.
     *
     * `tags.*` lässt sich nicht aufzählen: welche Marken ein Projekt setzt,
     * weiß nur die überwachte Anwendung. Der Name dahinter wird deshalb auf die
     * Zeichen begrenzt, die eine Marke haben darf.
     */
    private function attributeRule(): Closure
    {
        return static function (string $attribute, mixed $value, callable $fail): void {
            if (! is_string($value)) {
                return;
            }

            $name = strtolower(trim($value));

            if (in_array($name, self::ATTRIBUTES, true)) {
                return;
            }

            if (preg_match('/^tags\.[a-z0-9_.-]+$/', $name) === 1) {
                return;
            }

            $fail(__('grouping.validation.attribute'));
        };
    }
}
