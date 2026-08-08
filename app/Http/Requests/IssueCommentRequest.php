<?php

namespace App\Http\Requests;

use App\Models\IssueComment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Ein geschriebener oder geänderter Kommentar.
 *
 * Geprüft wird genau ein Feld, und die einzige Regel mit Inhalt ist die untere
 * Grenze: ein Kommentar aus Leerzeichen ist keiner. Die obere Grenze steht am
 * Modell und nicht als Zahl hier — die Spalte und die Prüfung sollen sich nicht
 * unabhängig voneinander ändern können.
 */
class IssueCommentRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:'.IssueComment::BODY_LIMIT],
        ];
    }

    /**
     * Der Kommentar, wie er gespeichert wird.
     *
     * Vorne und hinten beschnitten: ein Kommentar, der mit drei Leerzeilen
     * anfängt, reißt die Zeitleiste auseinander, ohne dass jemand das wollte.
     */
    public function body(): string
    {
        return trim((string) $this->validated('body'));
    }

    protected function prepareForValidation(): void
    {
        $body = $this->input('body');

        if (is_string($body)) {
            // Vor der Prüfung, damit „required" auf einen Kommentar aus
            // Leerzeichen anschlägt und nicht erst das Speichern eine leere
            // Zeile ergibt.
            $this->merge(['body' => trim($body)]);
        }
    }
}
