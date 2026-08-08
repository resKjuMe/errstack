<?php

namespace App\Models;

use App\Enums\AlertStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ein Zustandswechsel eines Alarms — der Verlauf, den jemand ansieht.
 *
 * Er ist nicht dasselbe wie die verschickte Benachrichtigung: die sagt, was
 * hinausgegangen ist, dieser Eintrag, was **festgestellt** wurde. Der
 * Unterschied fällt genau dann auf, wenn er zählt — ein Kanal, der gerade nicht
 * erreichbar war, ändert nichts daran, dass die Fehlerrate um 14:02 über die
 * Schwelle ging.
 *
 * Geschrieben wird eine Zeile **nur**, wenn der Wechsel tatsächlich vollzogen
 * wurde ({@see MetricAlert::transitionTo()}). Damit ist die Tabelle zugleich der
 * Beleg für die Zusage „höchstens eine Meldung je Übergang".
 *
 * @property int $id
 * @property int $metric_alert_id
 * @property AlertStatus $from_status
 * @property AlertStatus $to_status
 * @property float $value
 * @property float|null $threshold
 * @property float|null $baseline
 * @property CarbonImmutable $occurred_at
 */
class MetricAlertTransition extends Model
{
    /**
     * Die Art des Übergangs, aus Sicht dessen, der die Meldung liest.
     *
     * Vier Fälle, und sie unterscheiden sich nicht im Ziel, sondern in der
     * Richtung: von „in Ordnung" nach „Warnung" ist ein Auslösen, von „Warnung"
     * nach „kritisch" eine Verschärfung, und der Weg zurück ist beides Mal etwas
     * anderes. Wer nur das Ziel meldet, macht aus einer Entspannung eine zweite
     * Warnung.
     */
    public function kind(): string
    {
        if ($this->to_status === AlertStatus::Ok) {
            return 'resolved';
        }

        if ($this->from_status === AlertStatus::Ok) {
            return 'fired';
        }

        return $this->to_status->severity() > $this->from_status->severity()
            ? 'escalated'
            : 'eased';
    }

    /**
     * @return BelongsTo<MetricAlert, $this>
     */
    public function alert(): BelongsTo
    {
        return $this->belongsTo(MetricAlert::class, 'metric_alert_id');
    }

    /**
     * Der Verlauf, jüngster zuerst.
     *
     * @param  Builder<self>  $query
     */
    public function scopeLatestFirst(Builder $query): void
    {
        $query->orderByDesc('occurred_at')->orderByDesc('id');
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'metric_alert_id',
        'from_status',
        'to_status',
        'value',
        'threshold',
        'baseline',
        'occurred_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => AlertStatus::class,
            'to_status' => AlertStatus::class,
            'value' => 'float',
            'threshold' => 'float',
            'baseline' => 'float',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
