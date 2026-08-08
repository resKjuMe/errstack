<?php

namespace App\Models;

use App\Support\Ingest\Processing\Steps\RecordProfile;
use App\Support\Profiling\CallTree;
use Carbon\CarbonImmutable;
use Database\Factories\ProfileFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eine Laufzeitmessung: welche Code-Stellen während eines Aufrufs Rechenzeit
 * verbraucht haben.
 *
 * Die Transaktion sagt, **dass** ein Aufruf 1,4 Sekunden gebraucht hat, und
 * ihre Einzelschritte sagen, wie viel davon auf Datenbank und fremde Dienste
 * ging. Was in der verbleibenden Zeit im eigenen Code passiert ist, sagt keiner
 * von beiden — genau dafür ist diese Zeile da.
 *
 * Ein Profil steht **immer** an einer Transaktion ({@see RecordProfile}). Ohne
 * sie fehlt der Bezug: „irgendwo wurden 300 ms gerechnet" ist keine Auskunft,
 * mit der sich arbeiten lässt.
 *
 * @property int $id
 * @property int $project_id
 * @property int $transaction_id
 * @property int|null $ingest_payload_id
 * @property string $profile_id
 * @property string $trace_id
 * @property string $transaction_name
 * @property string|null $platform
 * @property string $environment
 * @property string|null $release
 * @property string|null $thread_id
 * @property CarbonImmutable $started_at
 * @property int $duration_us
 * @property int $sample_count
 * @property array<mixed> $frames
 * @property array<mixed> $tree
 */
class Profile extends Model
{
    /** @use HasFactory<ProfileFactory> */
    use HasFactory;

    /**
     * Zeitpunkte mit Millisekunden ablegen — dieselbe Begründung wie bei
     * {@see Transaction}: mehrere Profile eines Aufrufs liegen Millisekunden
     * auseinander, und ohne Bruchteile wäre ihre Reihenfolge verloren.
     *
     * @var string
     */
    protected $dateFormat = 'Y-m-d H:i:s.v';

    /**
     * Wie viele Profile eine zusammengefasste Ansicht höchstens übereinanderlegt.
     *
     * Die Zahl ist eine Abwägung zwischen Aussagekraft und Aufwand: unter zehn
     * Profilen sieht man Zufall, über hundert ändert sich am Bild nichts mehr,
     * und jedes weitere ist ein Baum mit tausenden Knoten, der gelesen,
     * ausgepackt und eingerechnet werden muss.
     */
    public const AGGREGATE_LIMIT = 100;

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Der Aufruf, den dieses Profil vermessen hat.
     *
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * Die Rohdaten, aus denen die Messung entstand — sofern sie noch da sind.
     *
     * @return BelongsTo<IngestPayload, $this>
     */
    public function payload(): BelongsTo
    {
        return $this->belongsTo(IngestPayload::class, 'ingest_payload_id');
    }

    /**
     * Der Aufrufbaum, aus den beiden Spalten zusammengesetzt.
     *
     * Nicht als `Attribute` mit Zwischenspeicher: der Baum eines Profils hat bis
     * zu {@see CallTree::MAX_NODES} Knoten, und eine Liste von hundert Profilen,
     * die ihre Bäume nebenbei im Speicher behalten, ist genau die Stelle, an der
     * eine Übersichtsseite umfällt. Wer den Baum braucht, holt ihn — und die
     * Liste braucht ihn nicht.
     */
    public function callTree(): CallTree
    {
        return CallTree::fromStorage($this->frames, $this->tree);
    }

    /**
     * Die Profile, die in eine zusammengefasste Ansicht eingehen.
     *
     * Die neuesten zuerst: eine Zusammenfassung soll den heutigen Zustand
     * zeigen. Wer den von vorletzter Woche will, schränkt den Zeitraum ein.
     *
     * @param  Builder<Profile>  $query
     * @return Builder<Profile>
     */
    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('started_at')->orderByDesc('id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'duration_us' => 'integer',
            'sample_count' => 'integer',
            'frames' => 'array',
            'tree' => 'array',
        ];
    }
}
