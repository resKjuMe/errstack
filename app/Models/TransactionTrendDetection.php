<?php

namespace App\Models;

use App\Enums\TrendDirection;
use Carbon\CarbonImmutable;
use Database\Factories\TransactionTrendDetectionFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ein festgestellter Trendbruch: diese Transaktion ist an diesem Zeitpunkt
 * umgeschlagen.
 *
 * Die Zeile ist **fortgeschrieben und nicht angehängt** — je Transaktion und
 * Richtung gibt es genau eine. Was sich zwischen zwei Durchläufen ändert, sind
 * die Zahlen: je mehr Messungen nach dem Bruch liegen, desto genauer stehen
 * Höhe und Aussagekraft da. Was sich **nicht** ändert, solange derselbe Bruch
 * gemeint ist, ist der Zeitpunkt — und daran hängt die Entscheidung, ob noch
 * einmal gemeldet wird ({@see App\Support\Performance\Trends\TrendScan}).
 *
 * @property int $id
 * @property int $project_id
 * @property string $environment
 * @property string $name
 * @property string $op
 * @property TrendDirection $direction
 * @property CarbonImmutable $breakpoint_at
 * @property int $before_p95_us
 * @property int $after_p95_us
 * @property int $before_count
 * @property int $after_count
 * @property float $change_ratio
 * @property float $z_score
 * @property int|null $deploy_id
 * @property CarbonImmutable $detected_at
 * @property CarbonImmutable|null $notified_at
 * @property CarbonImmutable|null $seen_at
 * @property int|null $seen_by_id
 */
class TransactionTrendDetection extends Model
{
    /** @use HasFactory<TransactionTrendDetectionFactory> */
    use HasFactory;

    /**
     * Nur die Verschlechterungen — das, wovon eine Meldung ausgeht.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function regressions(Builder $query): void
    {
        $query->where('direction', TrendDirection::Worse);
    }

    /**
     * Noch nicht als gesehen markiert.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function unseen(Builder $query): void
    {
        $query->whereNull('seen_at');
    }

    /**
     * Markiert die Feststellung als gesehen — oder hebt das wieder auf.
     *
     * Ein Schalter und kein Löschen: die Feststellung bleibt richtig, auch
     * nachdem jemand sie zur Kenntnis genommen hat. Wer sie wegräumen wollte,
     * hätte sie beim nächsten Durchlauf wieder da — die Messwerte stehen ja
     * weiterhin so, wie sie stehen.
     */
    public function markSeen(?User $user): void
    {
        $this->seen_at = CarbonImmutable::now();
        $this->seen_by_id = $user?->id;
        $this->save();
    }

    public function markUnseen(): void
    {
        $this->seen_at = null;
        $this->seen_by_id = null;
        $this->save();
    }

    /**
     * Die Operation, wie sie angezeigt wird — oder `null`, wenn es keine gibt.
     *
     * Die Spalte trägt die leere Zeichenkette statt `null`, weil sie im
     * eindeutigen Schlüssel steht; nach außen ist „keine Operation" aber kein
     * Wert, sondern eine Leerstelle.
     */
    public function operation(): ?string
    {
        return $this->op === '' ? null : $this->op;
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Deploy, $this>
     */
    public function deploy(): BelongsTo
    {
        return $this->belongsTo(Deploy::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function seenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seen_by_id');
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'environment',
        'name',
        'op',
        'direction',
        'breakpoint_at',
        'before_p95_us',
        'after_p95_us',
        'before_count',
        'after_count',
        'change_ratio',
        'z_score',
        'deploy_id',
        'detected_at',
        'notified_at',
        'seen_at',
        'seen_by_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => TrendDirection::class,
            'breakpoint_at' => 'immutable_datetime',
            'before_p95_us' => 'integer',
            'after_p95_us' => 'integer',
            'before_count' => 'integer',
            'after_count' => 'integer',
            'change_ratio' => 'float',
            'z_score' => 'float',
            'detected_at' => 'immutable_datetime',
            'notified_at' => 'immutable_datetime',
            'seen_at' => 'immutable_datetime',
        ];
    }
}
