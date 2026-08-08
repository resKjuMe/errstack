<?php

namespace App\Models;

use App\Enums\PerformanceProblem;
use Carbon\CarbonImmutable;
use Database\Factories\PerformanceDetectionFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Ein einzelner Fund: dieses Muster, in diesem Ablauf, mit diesen Schritten.
 *
 * Der Fund ist der **Beleg**. Ein Leistungsproblem ohne ihn wäre eine
 * Behauptung („hier gibt es N+1-Abfragen") ohne die Angabe, wo man nachsehen
 * kann — und genau das ist die erste Frage nach dem Lesen der Überschrift.
 *
 * Gespeichert wird höchstens **ein** Fund je Ablauf und Muster; dafür sorgt der
 * eindeutige Index. Ein Ablauf, der dieselbe Abfrage in zwei Schleifen
 * wiederholt, ergibt trotzdem zwei Funde — die Abfrageform steckt im
 * Fingerabdruck, und zwei Formen sind zwei Probleme.
 *
 * @property int $id
 * @property int $project_id
 * @property int|null $issue_id
 * @property int $transaction_id
 * @property string $trace_id
 * @property PerformanceProblem $problem
 * @property string $fingerprint
 * @property list<string> $span_ids
 * @property string|null $description
 * @property int $span_count
 * @property int $time_lost_us
 * @property array<string, mixed>|null $evidence
 * @property CarbonImmutable $occurred_at
 */
class PerformanceDetection extends Model
{
    /** @use HasFactory<PerformanceDetectionFactory> */
    use HasFactory;

    /**
     * Wie viele Beispiele die Detailansicht eines Eintrags zeigt.
     *
     * Drei, weil das die Frage beantwortet, die eine einzelne Zeile offenlässt:
     * ist der Fall von gestern der Normalfall oder der Ausreißer. Mehr wären
     * dieselbe Antwort mit mehr Zeilen.
     */
    public const EXAMPLE_LIMIT = 3;

    protected $dateFormat = 'Y-m-d H:i:s.v';

    /**
     * Legt den Fund an — oder lässt ihn liegen, wenn es ihn schon gibt.
     *
     * Rückgabe `null` heißt ausdrücklich **nicht** „Fehler", sondern „diesen
     * Ablauf hat schon jemand ausgewertet". Der Unterschied ist wichtig, weil
     * der Aufrufer daran entscheidet, ob er den Eintrag hochzählt: ein zweiter
     * Durchlauf über dieselbe Transaktion darf den Zähler nicht bewegen, sonst
     * wächst die Häufigkeit mit der Zahl der Wiederholungen statt mit der Zahl
     * der Vorfälle.
     *
     * Die Entscheidung fällt in der Datenbank und nicht in einem `exists()`
     * davor: zwei Arbeiter am selben Ablauf sind der Regelfall, nicht die
     * Ausnahme — der Auftrag darf mehrfach zugestellt werden.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function claim(array $attributes): ?self
    {
        try {
            return self::query()->create($attributes);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /**
     * @return BelongsTo<Issue, $this>
     */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Die betroffenen Schritte des Ablaufs, in der Reihenfolge, in der sie
     * gemeldet wurden.
     *
     * Bewusst eine eigene Abfrage und keine Beziehung: die Zuordnung läuft über
     * (`transaction_id`, `span_id`) und damit über zwei Spalten, von denen
     * Eloquent nur eine kennt. Der Name sagt es mit — `spans()` sähe wie eine
     * Beziehung aus, und der erste Zugriff als Eigenschaft
     * (`$detection->spans`) wäre ein Fehler, den niemand erwartet.
     *
     * @return Collection<int, TransactionSpan>
     */
    public function affectedSpans(): Collection
    {
        return TransactionSpan::query()
            ->where('transaction_id', $this->transaction_id)
            ->whereIn('span_id', $this->span_ids)
            ->orderBy('position')
            ->get();
    }

    protected $fillable = [
        'project_id',
        'issue_id',
        'transaction_id',
        'trace_id',
        'problem',
        'fingerprint',
        'span_ids',
        'description',
        'span_count',
        'time_lost_us',
        'evidence',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'problem' => PerformanceProblem::class,
            'span_ids' => 'array',
            'span_count' => 'integer',
            'time_lost_us' => 'integer',
            'evidence' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
