<?php

namespace App\Models;

use App\Enums\IssueAlertAction;
use App\Enums\IssueAlertMatch;
use App\Support\IssueAlerts\RuleAction;
use App\Support\IssueAlerts\RuleCondition;
use App\Support\IssueAlerts\RuleFilter;
use Database\Factories\IssueAlertRuleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eine Alarm-Regel für Fehler: Bedingung, Filter, Aktion — und eine Grenze,
 * wie oft sie sich zu Wort meldet.
 *
 * Die drei Teile stehen als JSON in der Zeile und werden hier in geprüfte
 * Wertobjekte übersetzt ({@see parsedConditions()},
 * {@see parsedFilters()}, {@see parsedActions()}). Was sich nicht deuten lässt, fällt dabei **weg** statt
 * einen Fehler zu werfen: die Auswertung läuft im Aufnahmeweg, und eine Regel
 * aus einer älteren Fassung darf dort nicht jede eingehende Meldung mitreißen.
 *
 * @property int $id
 * @property int $project_id
 * @property string $name
 * @property IssueAlertMatch $condition_match
 * @property IssueAlertMatch $filter_match
 * @property list<array<string, mixed>> $conditions
 * @property list<array<string, mixed>> $filters
 * @property list<array<string, mixed>> $actions
 * @property int $frequency_minutes
 * @property bool $is_active
 * @property int|null $triggers_count nur nach `withCount('triggers')`
 */
class IssueAlertRule extends Model
{
    /** @use HasFactory<IssueAlertRuleFactory> */
    use HasFactory;

    public const NAME_LIMIT = 120;

    /**
     * Wie viele Regeln ein Projekt haben darf.
     *
     * Die Grenze ist die Zusage des Aufnahmewegs: jede aktive Regel ist ein
     * Abgleich je eingehender Meldung. Fünfundzwanzig sind ein Blick auf eine
     * Handvoll Zeilen; einige hundert wären ein Aufschlag auf jede einzelne
     * Meldung — und zwar genau dann, wenn viele kommen.
     */
    public const MAX_PER_PROJECT = 25;

    /**
     * Die schmalste und die breiteste Häufigkeitsbegrenzung.
     *
     * Nach unten fünf Minuten: darunter wäre die Begrenzung keine mehr. Nach
     * oben eine Woche — was seltener meldet, ist kein Alarm mehr, sondern eine
     * Zusammenfassung (A6).
     */
    public const MIN_FREQUENCY_MINUTES = 5;

    public const MAX_FREQUENCY_MINUTES = 10080;

    /**
     * Die Anlässe dieser Regel.
     *
     * @return list<RuleCondition>
     */
    public function parsedConditions(): array
    {
        return array_values(array_filter(
            array_map(RuleCondition::fromArray(...), $this->arrayOf('conditions')),
        ));
    }

    /**
     * Die Einschränkungen dieser Regel.
     *
     * @return list<RuleFilter>
     */
    public function parsedFilters(): array
    {
        return array_values(array_filter(
            array_map(RuleFilter::fromArray(...), $this->arrayOf('filters')),
        ));
    }

    /**
     * Die Aktionen dieser Regel.
     *
     * @return list<RuleAction>
     */
    public function parsedActions(): array
    {
        return array_values(array_filter(
            array_map(RuleAction::fromArray(...), $this->arrayOf('actions')),
        ));
    }

    /**
     * Zielt eine Aktion dieser Regel auf einen bestimmten Kanal?
     *
     * @return list<int>
     */
    public function channelIds(): array
    {
        $ids = [];

        foreach ($this->parsedActions() as $action) {
            if ($action->type === IssueAlertAction::Channel && $action->channelId !== null) {
                $ids[] = $action->channelId;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Die Regeln, die der Aufnahmeweg für ein Projekt abzugleichen hat.
     *
     * @param  Builder<self>  $query
     */
    public function scopeActiveFor(Builder $query, int $projectId): void
    {
        $query->where('project_id', $projectId)
            ->where('is_active', true)
            ->orderBy('id');
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<IssueAlertTrigger, $this>
     */
    public function triggers(): HasMany
    {
        return $this->hasMany(IssueAlertTrigger::class);
    }

    /**
     * @return HasMany<IssueAlertState, $this>
     */
    public function states(): HasMany
    {
        return $this->hasMany(IssueAlertState::class);
    }

    /**
     * Die Rohliste einer der drei JSON-Spalten.
     *
     * @return list<array<string, mixed>>
     */
    private function arrayOf(string $attribute): array
    {
        $value = $this->getAttribute($attribute);

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_array(...)));
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'name',
        'condition_match',
        'filter_match',
        'conditions',
        'filters',
        'actions',
        'frequency_minutes',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'condition_match' => IssueAlertMatch::class,
            'filter_match' => IssueAlertMatch::class,
            'conditions' => 'array',
            'filters' => 'array',
            'actions' => 'array',
            'frequency_minutes' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
