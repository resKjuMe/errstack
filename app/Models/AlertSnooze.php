<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eine befristete Stummschaltung: diese Regel meldet sich bis dahin nicht.
 *
 * **Die Auswertung läuft weiter.** Das ist die ganze Zusage dieser Tabelle und
 * der Unterschied zum Schalter an der Regel selbst: Zustandswechsel und
 * Auslösungen werden weiter festgestellt und stehen im Verlauf — nur der Versand
 * unterbleibt. Wer eine Nacht Ruhe haben will, verliert damit nicht die
 * Auskunft, was in dieser Nacht los war.
 *
 * Wirksam wird sie an genau zwei Stellen, und zwar erst hinter der Feststellung:
 * {@see App\Support\Alerts\MetricAlertNotifier} und
 * {@see App\Support\IssueAlerts\IssueAlertNotifier}. Gelesen wird sie dort nicht
 * einzeln, sondern gebündelt über {@see App\Support\Alerts\AlertMute}.
 *
 * @property int $id
 * @property int|null $metric_alert_id
 * @property int|null $issue_alert_rule_id
 * @property int|null $user_id `null` = für alle
 * @property int|null $created_by_id
 * @property CarbonImmutable $until
 * @property CarbonImmutable $created_at
 */
class AlertSnooze extends Model
{
    /**
     * Die angebotenen Dauern in Minuten.
     *
     * Eine Stunde ist die kürzeste, die sich lohnt — darunter wartet man die
     * Störung ab, statt etwas einzustellen. Eine Woche ist die längste: was
     * länger still sein soll, ist keine Stummschaltung mehr, sondern eine Regel,
     * die niemand mehr will.
     *
     * @var list<int>
     */
    public const DURATIONS = [60, 120, 240, 480, 1440, 4320, 10080];

    public const MAX_MINUTES = 10080;

    /**
     * Die Stummschaltungen, die jetzt gelten.
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query, ?CarbonImmutable $now = null): void
    {
        $query->where('until', '>', $now ?? CarbonImmutable::now());
    }

    /**
     * Gilt sie für alle — oder nur für eine Person?
     */
    public function forEveryone(): bool
    {
        return $this->user_id === null;
    }

    /**
     * Wie lange sie noch reicht, in Minuten, aufgerundet.
     *
     * Aufgerundet, weil „noch 0 Minuten" für eine Stummschaltung, die noch gilt,
     * eine falsche Auskunft wäre.
     */
    public function remainingMinutes(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();

        if (! $this->until->greaterThan($now)) {
            return 0;
        }

        return (int) ceil($now->diffInSeconds($this->until) / 60);
    }

    /**
     * @return BelongsTo<MetricAlert, $this>
     */
    public function metricAlert(): BelongsTo
    {
        return $this->belongsTo(MetricAlert::class);
    }

    /**
     * @return BelongsTo<IssueAlertRule, $this>
     */
    public function issueAlertRule(): BelongsTo
    {
        return $this->belongsTo(IssueAlertRule::class);
    }

    /**
     * Für wen sie gilt — leer, wenn für alle.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Wer sie gesetzt hat.
     *
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'metric_alert_id',
        'issue_alert_rule_id',
        'user_id',
        'created_by_id',
        'until',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'until' => 'immutable_datetime',
        ];
    }

    /**
     * Eine Zeile ohne Regel wäre eine Stummschaltung ohne Gegenstand, eine mit
     * beiden zwei Regeln in einer Zeile. Die Datenbank kann das in beiden
     * unterstützten Fassungen nicht prüfen — hier fällt es dafür sofort auf und
     * nicht erst dort, wo eine Meldung ausbleibt.
     */
    protected static function booted(): void
    {
        static::saving(function (self $snooze): void {
            $hasMetric = $snooze->metric_alert_id !== null;
            $hasIssue = $snooze->issue_alert_rule_id !== null;

            if ($hasMetric === $hasIssue) {
                throw new \LogicException(
                    'Eine Stummschaltung gehört zu genau einer Regel — zu einem Schwellwert-Alarm oder zu einer Fehler-Regel.',
                );
            }
        });
    }

    /**
     * Der Endzeitpunkt zu einer Dauer.
     */
    public static function endOf(int $minutes, ?Carbon $now = null): CarbonImmutable
    {
        $now ??= Carbon::now();

        return CarbonImmutable::parse($now)->addMinutes($minutes);
    }
}
