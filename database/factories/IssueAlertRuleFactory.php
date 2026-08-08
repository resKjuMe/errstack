<?php

namespace Database\Factories;

use App\Enums\IssueAlertAction;
use App\Enums\IssueAlertCondition;
use App\Enums\IssueAlertFilter;
use App\Enums\IssueAlertMatch;
use App\Models\IssueAlertRule;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IssueAlertRule>
 */
class IssueAlertRuleFactory extends Factory
{
    /**
     * Die Vorgabe ist die Regel, die man als erste anlegt: neuer Fehler,
     * alle Kanäle.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => 'Neue Fehler',
            'condition_match' => IssueAlertMatch::Any,
            'filter_match' => IssueAlertMatch::All,
            'conditions' => [['type' => IssueAlertCondition::NewIssue->value]],
            'filters' => [],
            'actions' => [['type' => IssueAlertAction::Channel->value, 'channel_id' => null]],
            'frequency_minutes' => 30,
            'is_active' => true,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $conditions
     */
    public function conditions(array $conditions, IssueAlertMatch $match = IssueAlertMatch::Any): static
    {
        return $this->state(fn (): array => [
            'conditions' => $conditions,
            'condition_match' => $match,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $filters
     */
    public function filters(array $filters, IssueAlertMatch $match = IssueAlertMatch::All): static
    {
        return $this->state(fn (): array => [
            'filters' => $filters,
            'filter_match' => $match,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $actions
     */
    public function actions(array $actions): static
    {
        return $this->state(fn (): array => ['actions' => $actions]);
    }

    /**
     * Eine Regel, die auf die Häufigkeit sieht.
     */
    public function frequency(int $times, int $minutes): static
    {
        return $this->conditions([[
            'type' => IssueAlertCondition::Frequency->value,
            'value' => $times,
            'window' => $minutes,
        ]]);
    }

    /**
     * Eine Regel, die nur den genannten Grad durchlässt.
     */
    public function onlyLevel(string $level): static
    {
        return $this->filters([[
            'type' => IssueAlertFilter::Level->value,
            'comparison' => 'eq',
            'value' => $level,
        ]]);
    }
}
