<?php

namespace App\Http\Requests;

use App\Models\Transaction;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Die Eingabe der Transaktions-Detailseite: die globale Filterleiste plus die
 * Transaktion, um die es geht.
 *
 * Name und Operation stehen als Parameter in der Adresszeile und nicht als
 * Pfad-Abschnitte. Der Grund ist der Name selbst: er ist in aller Regel ein
 * Pfad (`/api/projekte/{projekt}/fehler`) und trägt damit genau die Zeichen,
 * die ein Pfad-Abschnitt nicht tragen kann. Kodiert wäre er zwar unterzubringen,
 * aber die Adresszeile wäre nicht mehr zu lesen — und lesbare, teilbare Adressen
 * sind die Zusage dieser Oberfläche.
 */
class TransactionDetailRequest extends GlobalFilterRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // Zusammengeführt und nicht ersetzt, wie in der Übersicht: sonst fielen
        // die Felder der Filterleiste aus `validated()` heraus.
        return parent::rules() + [
            'name' => ['required', 'string', 'max:'.Transaction::NAME_LIMIT],
            // Die leere Zeichenkette ist ein gültiger Wert und nicht das Fehlen
            // einer Angabe: die Vorberechnung führt „ohne Operation" genau so
            // ({@see \App\Models\TransactionAggregate}).
            'op' => ['nullable', 'string', 'max:'.Transaction::OP_LIMIT],
        ];
    }

    public function name(): string
    {
        return (string) $this->validated('name');
    }

    public function op(): string
    {
        return (string) ($this->validated('op') ?? '');
    }
}
