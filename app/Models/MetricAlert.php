<?php

namespace App\Models;

use App\Enums\AlertComparison;
use App\Enums\AlertDirection;
use App\Enums\AlertMetric;
use App\Enums\AlertStatus;
use Carbon\CarbonImmutable;
use Database\Factories\MetricAlertFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Ein Schwellwert-Alarm: eine Kennzahl, ein Zeitfenster, zwei Schwellen — und
 * ein Zustand.
 *
 * Der Zustand ist das Besondere. Ein Alarm ohne ihn wäre eine Abfrage, die man
 * regelmäßig stellt, und jede Antwort „über der Schwelle" wäre eine Meldung: bei
 * minütlicher Auswertung sechzig in der Stunde für dieselbe Lage. Mit ihm ist der
 * Alarm ein Gegenstand mit Geschichte, und gemeldet wird nur, was sich **ändert**.
 *
 * **Der Wechsel selbst ist eine bedingte Anweisung und keine Sperre**
 * ({@see transitionTo()}). Läuft der Zeitplan doppelt an — zwei Server, ein
 * hängender Durchlauf —, sehen beide Arbeiter denselben alten Zustand. Die
 * Anweisung trifft aber nur eine Zeile, und nur wer sie trifft, meldet. Das ist
 * die technische Einlösung der Zusage „höchstens eine Meldung je Übergang".
 *
 * @property int $id
 * @property int $project_id
 * @property string $name
 * @property AlertMetric $metric
 * @property AlertDirection $direction
 * @property AlertComparison $comparison
 * @property string|null $environment
 * @property string|null $transaction_name
 * @property int $window_minutes
 * @property float|null $warning_threshold
 * @property float|null $critical_threshold
 * @property float|null $resolve_threshold
 * @property int $minimum_samples
 * @property bool $is_active
 * @property AlertStatus $status
 * @property CarbonImmutable|null $status_since
 * @property CarbonImmutable|null $last_evaluated_at
 * @property float|null $last_value
 * @property float|null $last_baseline
 */
class MetricAlert extends Model
{
    /** @use HasFactory<MetricAlertFactory> */
    use HasFactory;

    public const NAME_LIMIT = 120;

    /**
     * Wie viele Alarme ein Projekt haben darf.
     *
     * Die Grenze ist keine Sparsamkeit, sondern die Zusage des Zeitplans: jeder
     * aktive Alarm ist eine Abfrage je Minute. Fünfzig sind eine Zahl, die eine
     * Datenbank nebenbei erledigt; fünftausend wären ein Dauerlauf, der die
     * Aufnahme ausbremst — und zwar genau dann, wenn viel los ist.
     */
    public const MAX_PER_PROJECT = 50;

    /**
     * Die schmalste und die breiteste Fensterbreite.
     *
     * Eine Minute ist die Auflösung des Zeitplans; darunter wäre die Angabe
     * nicht einzulösen. Ein Tag ist die andere Grenze: was darüber hinausgeht,
     * ist keine Alarmierung mehr, sondern ein Bericht.
     */
    public const MIN_WINDOW_MINUTES = 1;

    public const MAX_WINDOW_MINUTES = 1440;

    /**
     * Vollzieht einen Zustandswechsel — genau einmal.
     *
     * Der `where`-Teil auf dem alten Zustand ist der ganze Trick: er macht aus
     * „lesen, entscheiden, schreiben" eine einzige Anweisung, die entweder
     * greift oder nicht. Zwei gleichzeitig laufende Auswertungen kommen damit zu
     * demselben Ergebnis, ohne einander zu sperren — und nur eine von beiden
     * meldet.
     *
     * @return bool `false`, wenn ein anderer Durchlauf schneller war.
     */
    public function transitionTo(AlertStatus $to, Carbon $now): bool
    {
        $from = $this->status;

        if ($from === $to) {
            return false;
        }

        $changed = self::query()
            ->whereKey($this->getKey())
            ->where('status', $from->value)
            ->update([
                'status' => $to->value,
                'status_since' => $now,
                'updated_at' => $now,
            ]);

        if ($changed !== 1) {
            return false;
        }

        $this->status = $to;
        $this->status_since = CarbonImmutable::parse($now);
        $this->syncOriginalAttributes(['status', 'status_since']);

        return true;
    }

    /**
     * Hält fest, was zuletzt gerechnet wurde.
     *
     * Getrennt vom Zustandswechsel und ausdrücklich **ohne** Bedingung: dieser
     * Vermerk sagt „der Alarm lebt", und er soll auch dann stimmen, wenn sich am
     * Zustand nichts geändert hat. Ohne ihn wäre eine stille Regel nicht von
     * einer kaputten zu unterscheiden.
     */
    public function recordEvaluation(?float $value, ?float $baseline, Carbon $now): void
    {
        self::query()->whereKey($this->getKey())->update([
            'last_evaluated_at' => $now,
            'last_value' => $value,
            'last_baseline' => $baseline,
            'updated_at' => $now,
        ]);

        $this->last_evaluated_at = CarbonImmutable::parse($now);
        $this->last_value = $value;
        $this->last_baseline = $baseline;
        $this->syncOriginalAttributes(['last_evaluated_at', 'last_value', 'last_baseline']);
    }

    /**
     * Die Schwelle, die zu einem Zustand gehört.
     */
    public function thresholdFor(AlertStatus $status): ?float
    {
        return match ($status) {
            AlertStatus::Critical => $this->critical_threshold,
            AlertStatus::Warning => $this->warning_threshold,
            AlertStatus::Ok => $this->resolve_threshold,
        };
    }

    /**
     * Die Einheit, in der die Schwellen dieses Alarms zu lesen sind.
     */
    public function unit(): string
    {
        return $this->comparison->unitFor($this->metric);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<MetricAlertTransition, $this>
     */
    public function transitions(): HasMany
    {
        return $this->hasMany(MetricAlertTransition::class);
    }

    /**
     * Die Alarme, die der Zeitplan abzuarbeiten hat: aktive zuerst die, deren
     * Auswertung am längsten zurückliegt.
     *
     * `nullsFirst` gibt es nicht in beiden Datenbanken; stattdessen ein
     * Ausdruck, der einen fehlenden Zeitpunkt als „ganz alt" behandelt. Ein
     * frisch angelegter Alarm ist damit der Erste und nicht der Letzte — er ist
     * derjenige, von dem noch niemand weiß, ob er greift.
     *
     * @param  Builder<self>  $query
     */
    public function scopeDue(Builder $query): void
    {
        $query->where('is_active', true)
            ->orderByRaw('case when last_evaluated_at is null then 0 else 1 end')
            ->orderBy('last_evaluated_at')
            ->orderBy('id');
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'name',
        'metric',
        'direction',
        'comparison',
        'environment',
        'transaction_name',
        'window_minutes',
        'warning_threshold',
        'critical_threshold',
        'resolve_threshold',
        'minimum_samples',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metric' => AlertMetric::class,
            'direction' => AlertDirection::class,
            'comparison' => AlertComparison::class,
            'status' => AlertStatus::class,
            'window_minutes' => 'integer',
            'warning_threshold' => 'float',
            'critical_threshold' => 'float',
            'resolve_threshold' => 'float',
            'last_value' => 'float',
            'last_baseline' => 'float',
            'minimum_samples' => 'integer',
            'is_active' => 'boolean',
            'status_since' => 'immutable_datetime',
            'last_evaluated_at' => 'immutable_datetime',
        ];
    }
}
