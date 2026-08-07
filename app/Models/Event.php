<?php

namespace App\Models;

use App\Enums\EventLevel;
use App\Support\Ingest\Normalization\NormalizedEvent;
use App\Support\Ingest\Normalization\Sections\Sdk;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eine ausgewertete Meldung — das einheitliche Modell hinter dem, was ein SDK
 * geschickt hat.
 *
 * Ab hier interessiert die Herkunft nicht mehr: ob die Meldung aus PHP, aus
 * JavaScript oder aus einem SDK kam, das es beim Bau dieser Anwendung noch
 * nicht gab, ändert nichts mehr an der Form. Genau das ist der Zweck der
 * Normalisierung, und alles Weitere — Gruppierung, Suche, Anzeige,
 * Benachrichtigung — baut darauf auf.
 *
 * Das Original bleibt daneben liegen ({@see payload()}). Ein Datensatz hier ist
 * damit jederzeit neu herstellbar; er ist eine Auslegung der Rohdaten, nicht
 * ihr Ersatz.
 *
 * @property int $id
 * @property int $project_id
 * @property int $ingest_payload_id
 * @property string $event_id
 * @property EventLevel $level
 * @property string $platform
 * @property string|null $title
 * @property string|null $culprit
 * @property string|null $transaction
 * @property string|null $logger
 * @property string|null $environment
 * @property string|null $release
 * @property string|null $dist
 * @property string|null $server_name
 * @property Carbon $occurred_at
 * @property Carbon $received_at
 * @property array<string, mixed>|null $message
 * @property list<array<string, mixed>>|null $exceptions
 * @property list<array<string, mixed>>|null $threads
 * @property array<string, mixed>|null $request
 * @property array<string, mixed>|null $user
 * @property array<string, mixed>|null $contexts
 * @property list<array<string, mixed>>|null $breadcrumbs
 * @property array<string, string>|null $tags
 * @property array<string, mixed>|null $extra
 * @property array<string, mixed>|null $sdk
 * @property array<string, string>|null $modules
 * @property array<string, mixed>|null $unknown
 * @property array{truncated?: list<string>, invalid?: list<string>}|null $notes
 */
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory;

    /**
     * Legt das Ergebnis der Normalisierung ab.
     *
     * `updateOrCreate` und nicht `create`: dieselbe Meldung darf ein zweites
     * Mal durch die Kette laufen — nach einem Fehlschlag, nach einer
     * Verbesserung an einem Schritt. Dann soll der Datensatz **ersetzt**
     * werden. Ein zweiter daneben wäre schlimmer als gar keiner: er stünde
     * doppelt in jeder Zählung, und welcher von beiden gilt, wüsste niemand.
     */
    public static function store(IngestPayload $payload, NormalizedEvent $event): self
    {
        return self::query()->updateOrCreate(
            ['ingest_payload_id' => $payload->id],
            [
                'project_id' => $payload->project_id,
                'event_id' => $event->eventId,
                'level' => $event->level,
                'platform' => $event->platform,
                'title' => $event->title,
                'culprit' => $event->culprit,
                'transaction' => $event->transaction,
                'logger' => $event->logger,
                'environment' => $event->environment,
                'release' => $event->release,
                'dist' => $event->dist,
                'server_name' => $event->serverName,
                'occurred_at' => $event->timestamp,
                'received_at' => $payload->created_at ?? Carbon::now(),
                'message' => $event->message,
                'exceptions' => $event->exceptions === [] ? null : $event->exceptions,
                'threads' => $event->threads === [] ? null : $event->threads,
                'request' => $event->request,
                'user' => $event->user,
                'contexts' => $event->contexts,
                'breadcrumbs' => $event->breadcrumbs === [] ? null : $event->breadcrumbs,
                'tags' => $event->tags === [] ? null : $event->tags,
                'extra' => $event->extra,
                'sdk' => $event->sdk,
                'modules' => $event->modules === [] ? null : $event->modules,
                'unknown' => $event->unknown,
                'notes' => $event->notes,
            ],
        );
    }

    /**
     * Trägt die Meldung eine Ausnahme — oder ist sie eine bloße Nachricht?
     */
    public function hasException(): bool
    {
        return ($this->exceptions ?? []) !== [];
    }

    /**
     * Der Stacktrace der zuletzt geworfenen Ausnahme.
     *
     * Die Ursachenkette ist von der ältesten Ursache an geordnet; die letzte
     * Ausnahme ist die, die die Anwendung gesehen hat.
     *
     * @return list<array<string, mixed>>
     */
    public function frames(): array
    {
        $exceptions = $this->exceptions ?? [];

        if ($exceptions === []) {
            return [];
        }

        $frames = $exceptions[array_key_last($exceptions)]['frames'] ?? null;

        /** @var list<array<string, mixed>> */
        return is_array($frames) ? $frames : [];
    }

    /**
     * Das absendende SDK in der Kurzform „name/version".
     */
    public function sdkIdentifier(): ?string
    {
        return Sdk::identifier($this->sdk);
    }

    /**
     * Wurde bei dieser Meldung etwas gekürzt oder verworfen?
     *
     * Die Frage gehört an die Anzeige: ein abgeschnittener Stacktrace sieht aus
     * wie ein kurzer, und wer das nicht weiß, sucht an der falschen Stelle.
     */
    public function wasReduced(): bool
    {
        return ($this->notes ?? []) !== [];
    }

    /**
     * Die Meldungen eines Projekts, jüngste zuerst — die Liste, die als Erstes
     * jemand aufschlägt.
     *
     * @param  Builder<self>  $query
     */
    public function scopeLatestFirst(Builder $query): void
    {
        $query->orderByDesc('occurred_at')->orderByDesc('id');
    }

    /**
     * @return BelongsTo<IngestPayload, $this>
     */
    public function payload(): BelongsTo
    {
        return $this->belongsTo(IngestPayload::class, 'ingest_payload_id');
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'ingest_payload_id',
        'event_id',
        'level',
        'platform',
        'title',
        'culprit',
        'transaction',
        'logger',
        'environment',
        'release',
        'dist',
        'server_name',
        'occurred_at',
        'received_at',
        'message',
        'exceptions',
        'threads',
        'request',
        'user',
        'contexts',
        'breadcrumbs',
        'tags',
        'extra',
        'sdk',
        'modules',
        'unknown',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'level' => EventLevel::class,
            'occurred_at' => 'datetime',
            'received_at' => 'datetime',
            'message' => 'array',
            'exceptions' => 'array',
            'threads' => 'array',
            'request' => 'array',
            'user' => 'array',
            'contexts' => 'array',
            'breadcrumbs' => 'array',
            'tags' => 'array',
            'extra' => 'array',
            'sdk' => 'array',
            'modules' => 'array',
            'unknown' => 'array',
            'notes' => 'array',
        ];
    }
}
