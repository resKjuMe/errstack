<?php

namespace App\Http\Requests;

use App\Models\Transaction;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Die Eingabe der Profil-Übersicht: die globale Filterleiste plus das, was nur
 * diese Seite kennt — die gewählte Transaktion und die beiden Versionen, die
 * verglichen werden.
 *
 * Wie überall steht der ganze Zustand in der Adresszeile: ein Neuladen behält
 * ihn, und ein geteilter Link zeigt beim Empfänger denselben Vergleich. Gerade
 * hier ist das keine Förmlichkeit — „schau dir an, was 1.4 kaputt gemacht hat"
 * ist die häufigste Verwendung dieser Seite.
 */
class ProfileIndexRequest extends GlobalFilterRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // Zusammengeführt und nicht ersetzt: die Felder der Filterleiste müssen
        // stehen bleiben, sonst fielen sie stillschweigend aus `validated()`
        // heraus und die Seite zeigte den Standard-Zeitraum, egal was in der
        // Adresszeile steht.
        return parent::rules() + [
            'transaction' => ['nullable', 'string', 'max:'.Transaction::NAME_LIMIT],
            'release' => ['nullable', 'string', 'max:255'],
            'compare' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Die gewählte Transaktion. Ohne sie zeigt die Seite nur die Liste — eine
     * Zusammenfassung über verschiedene Transaktionen hinweg wäre der
     * Durchschnitt aus Anmeldeseite und nächtlichem Import.
     */
    public function transactionName(): ?string
    {
        return $this->text('transaction');
    }

    /**
     * Die Version, deren Profile die Zusammenfassung zeigt.
     */
    public function release(): ?string
    {
        return $this->text('release');
    }

    /**
     * Die Version, gegen die verglichen wird.
     *
     * Getrennt von {@see release()} und nicht als Paar: der Regelfall ist die
     * Zusammenfassung **ohne** Vergleich, und eine Seite, die zwei Versionen
     * verlangt, bevor sie überhaupt etwas zeigt, wäre für diesen Fall im Weg.
     */
    public function compareRelease(): ?string
    {
        return $this->text('compare');
    }

    private function text(string $field): ?string
    {
        $value = $this->validated($field);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
