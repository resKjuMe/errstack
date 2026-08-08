<?php

namespace App\Http\Requests;

use App\Enums\IssueStatus;
use App\Enums\PerformanceIssueSort;
use App\Enums\PerformanceProblem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Die Eingaben der Leistungsproblem-Liste: Sortierung, Zustand, Muster.
 *
 * Aufgebaut wie {@see IssueListRequest} und aus demselben Grund: der Zustand
 * der Ansicht steht vollständig in der Adresszeile, damit sie das Neuladen
 * übersteht und sich als Link teilen lässt. Was hier dazukommt, ist der
 * Muster-Filter; was fehlt, ist der Merkmal-Filter — Merkmale hängen an
 * Ereignissen, und ein Leistungsproblem hat keine.
 */
class PerformanceIssueListRequest extends GlobalFilterRequest
{
    /**
     * Der Wert für „Zustand egal".
     *
     * Derselbe wie in der Fehlerliste: es ist dieselbe Auswahl, und zwei
     * Schreibweisen für dasselbe wären ein Fehler, der erst auffällt, wenn
     * jemand einen Link von einer Liste in die andere kopiert.
     */
    public const STATUS_ANY = IssueListRequest::STATUS_ANY;

    /**
     * Der Wert für „alle Muster".
     */
    public const PROBLEM_ANY = 'alle';

    /**
     * @return array<string, list<ValidationRule|string>>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'sort' => ['nullable', Rule::enum(PerformanceIssueSort::class)],
            'status' => ['nullable', Rule::in([self::STATUS_ANY, ...array_column(IssueStatus::cases(), 'value')])],
            'problem' => ['nullable', Rule::in([self::PROBLEM_ANY, ...array_column(PerformanceProblem::cases(), 'value')])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function sort(): PerformanceIssueSort
    {
        return PerformanceIssueSort::tryFrom((string) $this->validated('sort')) ?? PerformanceIssueSort::default();
    }

    /**
     * Der gewählte Zustand — `null` heißt „alle".
     *
     * Ohne Angabe gilt der Vorgabewert und nicht „alle": eine Liste, die
     * erledigte Einträge mitzeigt, ist beim Aufschlagen länger, als sie sein
     * müsste.
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
     * Das gewählte Muster — `null` heißt „alle".
     *
     * Anders als beim Zustand ist „alle" hier der Vorgabewert: wer die Liste
     * aufschlägt, will wissen, was es überhaupt gibt.
     */
    public function problem(): ?PerformanceProblem
    {
        return PerformanceProblem::tryFrom((string) ($this->validated('problem') ?? ''));
    }

    /**
     * Die Werte, wie die Oberfläche sie in ihren Feldern führt.
     *
     * @return array{sort: string, status: string, problem: string}
     */
    public function listValues(): array
    {
        $status = $this->status();
        $problem = $this->problem();

        return [
            'sort' => $this->sort()->value,
            'status' => $status === null ? self::STATUS_ANY : $status->value,
            'problem' => $problem === null ? self::PROBLEM_ANY : $problem->value,
        ];
    }
}
