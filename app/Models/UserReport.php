<?php

namespace App\Models;

use App\Enums\UserReportSource;
use App\Enums\UserReportStatus;
use Database\Factories\UserReportFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Was eine betroffene Person beschrieben hat — mit oder ohne Ereignisbezug.
 *
 * Zwei Wege führen hierher, und beide enden in derselben Zeile: der klassische
 * Absturzbericht eines SDK („beschreiben Sie, was Sie getan haben") und die
 * freie Zuschrift aus dem Feedback-Widget. Der Unterschied steht nicht in einer
 * Spalte, sondern in {@see source()}: er ist genau die Frage, ob ein Ereignis
 * genannt wurde.
 *
 * @property int $id
 * @property int $project_id
 * @property int|null $ingest_payload_id
 * @property string|null $event_reference
 * @property int|null $event_id
 * @property int|null $issue_id
 * @property string|null $name
 * @property string|null $email
 * @property string $comments
 * @property string|null $url
 * @property UserReportStatus $status
 * @property int|null $assigned_to
 * @property Carbon|null $assigned_at
 * @property Carbon $received_at
 */
class UserReport extends Model
{
    /** @use HasFactory<UserReportFactory> */
    use HasFactory;

    /** Längster Text, den eine Rückmeldung tragen darf. */
    public const COMMENTS_LIMIT = 4096;

    /** Längster Name. */
    public const NAME_LIMIT = 200;

    /** Längste E-Mail-Adresse — die Grenze der Spalte. */
    public const EMAIL_LIMIT = 255;

    /** Längste Adresse der Seite, auf der die Rückmeldung entstand. */
    public const URL_LIMIT = 2048;

    /**
     * Verknüpft diese Rückmeldung mit dem Ereignis, dessen Nummer sie nennt.
     *
     * Der Bezug wird beim Anlegen versucht und später erneut — siehe
     * {@see link()}. Ohne genannte Nummer gibt es nichts zu suchen.
     */
    public function resolveLink(): bool
    {
        if ($this->event_reference === null || $this->event_id !== null) {
            return false;
        }

        $event = Event::query()
            ->where('project_id', $this->project_id)
            ->where('event_id', $this->event_reference)
            ->latest('id')
            ->first();

        if ($event === null) {
            return false;
        }

        $this->event_id = $event->id;
        $this->issue_id = $event->group?->issue_id;
        $this->save();

        return true;
    }

    /**
     * Holt für eine ganze Seite nach, was beim Anlegen noch nicht ging.
     *
     * Der Grund ist die Reihenfolge, in der die Dinge eintreffen: eine
     * Rückmeldung kann vor „ihrem" Ereignis ankommen — das SDK schickt sie
     * getrennt, und die Auswertung des Ereignisses läuft in der Warteschlange.
     * Wer nur beim Anlegen verknüpft, hat für diese Fälle dauerhaft eine
     * Zuschrift ohne Sprungziel, obwohl der Fehler längst in der Liste steht.
     *
     * Deshalb wird beim Anzeigen nachgesehen — aber nur für die Zeilen, die
     * überhaupt in Frage kommen, und in **einer** Abfrage für die ganze Seite.
     * Ein eigener Hintergrundlauf dafür wäre ein Zeitplan mehr für eine Arbeit,
     * die genau dann anfällt, wenn jemand hinsieht.
     *
     * @param  Collection<int, self>  $reports
     */
    public static function link(Collection $reports): void
    {
        $pending = $reports->filter(
            static fn (self $report): bool => $report->event_reference !== null && $report->event_id === null,
        );

        if ($pending->isEmpty()) {
            return;
        }

        $events = Event::query()
            ->with('group:id,issue_id')
            ->whereIn('project_id', $pending->pluck('project_id')->unique()->all())
            ->whereIn('event_id', $pending->pluck('event_reference')->unique()->all())
            ->get()
            ->keyBy(static fn (Event $event): string => $event->project_id.':'.$event->event_id);

        foreach ($pending as $report) {
            $event = $events->get($report->project_id.':'.$report->event_reference);

            if (! $event instanceof Event) {
                continue;
            }

            $report->event_id = $event->id;
            $report->issue_id = $event->group?->issue_id;
            $report->save();
        }
    }

    /**
     * Die Art dieser Rückmeldung — abgeleitet, nicht gespeichert.
     */
    public function source(): UserReportSource
    {
        return $this->event_reference === null
            ? UserReportSource::Standalone
            : UserReportSource::CrashReport;
    }

    /**
     * Übergibt die Rückmeldung an jemanden — oder gibt sie wieder frei.
     *
     * Der Zeitpunkt wird mitgeführt, weil „seit wann liegt das bei mir?" die
     * Frage ist, die eine Zuweisung erst zu einer Zusage macht.
     */
    public function assignTo(?User $user): void
    {
        $this->assigned_to = $user?->id;
        $this->assigned_at = $user === null ? null : now();

        $this->save();
    }

    /**
     * Die Rückmeldungen eines Projekts, neueste zuerst.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function latestFirst(Builder $query): void
    {
        $query->orderByDesc('received_at')->orderByDesc('id');
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Issue, $this>
     */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    /**
     * Das Ereignis, auf das sich die Rückmeldung bezieht.
     *
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @return BelongsTo<IngestPayload, $this>
     */
    public function payload(): BelongsTo
    {
        return $this->belongsTo(IngestPayload::class, 'ingest_payload_id');
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'ingest_payload_id',
        'event_reference',
        'event_id',
        'issue_id',
        'name',
        'email',
        'comments',
        'url',
        'status',
        'assigned_to',
        'assigned_at',
        'received_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => UserReportStatus::class,
            'assigned_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }
}
