<?php

namespace App\Http\Requests;

use App\Enums\IssueSort;
use App\Enums\IssueStatus;
use App\Support\Issues\IssueFields;
use App\Support\Search\SearchExpression;
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
            // Die Suchleiste. Die Länge ist großzügig, aber begrenzt: ein
            // Ausdruck, der nicht mehr in eine Adresszeile passt, ist keiner
            // mehr, und ungeprüft stünde er in jedem Datenbank-Protokoll.
            'q' => ['nullable', 'string', 'max:500'],
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
     * Der übersetzte Suchausdruck.
     *
     * Die Zeitzone des Betrachters fährt mit, weil sie zur Bedeutung gehört:
     * `firstSeen:2026-03-01` meint **seinen** ersten März und nicht den in UTC —
     * sonst fehlten je nach Standort die ersten oder die letzten Stunden.
     */
    public function search(): SearchExpression
    {
        return SearchExpression::compile(
            $this->searchInput(),
            new IssueFields($this->filter()->timezone),
        );
    }

    /**
     * Die Eingabe, wie sie im Suchfeld stehen soll — unverändert.
     *
     * Nicht die zerlegte Fassung wieder zusammengesetzt: wer `firstRelease:1.0.0`
     * tippt und nach dem Absenden `firstrelease:1.0.0` vorfindet, bekommt eine
     * Antwort auf eine Frage, die er so nicht gestellt hat.
     */
    public function searchInput(): string
    {
        return trim((string) ($this->validated('q') ?? ''));
    }

    /**
     * Die Werte, wie die Oberfläche sie in ihren Feldern führt.
     *
     * @return array{sort: string, status: string, q: string}
     */
    public function listValues(): array
    {
        $status = $this->status();

        return [
            'sort' => $this->sort()->value,
            'status' => $status === null ? self::STATUS_ANY : $status->value,
            'q' => $this->searchInput(),
        ];
    }
}
