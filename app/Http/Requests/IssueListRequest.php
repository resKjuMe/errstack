<?php

namespace App\Http\Requests;

use App\Enums\IssueSort;
use App\Enums\IssueStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Die Fehlerliste als Eingabe: die Felder der globalen Filterleiste, dazu
 * Sortierung, Zustand und Seite.
 *
 * Erbt von {@see GlobalFilterRequest}, statt die Filterfelder noch einmal zu
 * beschreiben — die Leiste soll auf jeder Seite nach denselben Regeln geprüft
 * werden, und eine Kopie der Regeln wäre die Stelle, an der das auseinanderläuft.
 *
 * Zustand und Sortierung stehen wie der Filter in der Adresszeile: ein Link auf
 * „die häufigsten offenen Fehler der letzten 24 Stunden" ist damit ein Link und
 * keine Klickanleitung.
 */
class IssueListRequest extends GlobalFilterRequest
{
    /** Der Wert, mit dem der Zustandsfilter abgeschaltet wird. */
    public const STATUS_ANY = 'alle';

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'sort' => ['nullable', Rule::enum(IssueSort::class)],
            // `alle` ist kein Zustand, sondern dessen Abwesenheit — deshalb ein
            // eigener Wert und nicht der leere Text: der steht in einer
            // Adresszeile für „Feld vergessen" und nicht für „ausdrücklich alle".
            'status' => ['nullable', Rule::in([self::STATUS_ANY, ...array_column(IssueStatus::cases(), 'value')])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * Die gewählte Sortierung — ohne Angabe die Voreinstellung.
     */
    public function sort(): IssueSort
    {
        return IssueSort::tryFrom((string) $this->validated('sort')) ?? IssueSort::default();
    }

    /**
     * Der gewählte Zustand, oder `null` für „alle".
     *
     * Ohne Angabe sind es die offenen Einträge. Die Vorgabe ist nicht Bequemlichkeit:
     * eine Liste, die erledigte und stummgeschaltete Fehler mitzeigt, ist nach
     * ein paar Wochen keine Arbeitsliste mehr.
     */
    public function status(): ?IssueStatus
    {
        $status = (string) ($this->validated('status') ?? '');

        if ($status === self::STATUS_ANY) {
            return null;
        }

        return IssueStatus::tryFrom($status) ?? IssueStatus::DEFAULT;
    }

    /**
     * Die Werte, wie die Oberfläche sie in ihren Feldern führt.
     *
     * @return array{sort: string, status: string}
     */
    public function listValues(): array
    {
        return [
            'sort' => $this->sort()->value,
            'status' => $this->status()?->value ?? self::STATUS_ANY,
        ];
    }
}
