<?php

namespace App\Models;

use App\Enums\IssueAlertCondition;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eine Auslösung: welche Regel wann für welchen Fehler gegriffen hat.
 *
 * Der Verlauf, den jemand nach einer Störung ansieht — und der Beleg dafür,
 * dass die Häufigkeitsbegrenzung eingehalten wurde. Beides wäre aus den
 * Zustellungen (A1) nicht zu holen: die sagen, was verschickt wurde, nicht, was
 * festgestellt wurde. Eine Regel ohne aktiven Kanal löst aus und verschickt
 * nichts — genau diese Lage sucht man, wenn eine Meldung ausgeblieben ist.
 *
 * @property int $id
 * @property int $issue_alert_rule_id
 * @property int $issue_id
 * @property list<string> $conditions
 * @property int $delivery_count
 * @property CarbonImmutable $occurred_at
 */
class IssueAlertTrigger extends Model
{
    /**
     * Die Anlässe dieser Auslösung als Aufzählungswerte.
     *
     * @return list<IssueAlertCondition>
     */
    public function conditionTypes(): array
    {
        return array_values(array_filter(array_map(
            IssueAlertCondition::tryFrom(...),
            $this->conditions,
        )));
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeLatestFirst(Builder $query): void
    {
        $query->orderByDesc('occurred_at')->orderByDesc('id');
    }

    /**
     * @return BelongsTo<IssueAlertRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(IssueAlertRule::class, 'issue_alert_rule_id');
    }

    /**
     * @return BelongsTo<Issue, $this>
     */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'issue_alert_rule_id',
        'issue_id',
        'conditions',
        'delivery_count',
        'occurred_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'delivery_count' => 'integer',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
