<?php

namespace App\Models;

use App\Support\Issues\IssueComments;
use Database\Factories\IssueCommentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Ein Kommentar an einem Fehler-Eintrag.
 *
 * Der Gegenpol zum Aktivitätsvermerk ({@see IssueActivity}): der Vermerk hält
 * fest, was geschehen ist, und ist unveränderlich; der Kommentar ist das, was
 * jemand dazu sagt, und darf korrigiert werden. Beide stehen in derselben
 * Zeitleiste — deshalb die getrennten Tabellen mit gemeinsamer Anzeige.
 *
 * Geschrieben, geändert und gelöscht wird ausschließlich über
 * {@see IssueComments}: dort hängt das Auflösen der Nennungen und das
 * Benachrichtigen daran, und ein `IssueComment::create()` an anderer Stelle
 * wäre ein Kommentar, von dem der Genannte nie erfährt.
 *
 * @property int $id
 * @property int $issue_id
 * @property int $project_id
 * @property int|null $user_id
 * @property string|null $author_name
 * @property string $body
 * @property Carbon|null $edited_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'issue_id',
    'project_id',
    'user_id',
    'author_name',
    'body',
    'edited_at',
])]
class IssueComment extends Model
{
    /** @use HasFactory<IssueCommentFactory> */
    use HasFactory;

    /**
     * Längster Kommentar.
     *
     * Großzügig genug für eine Fehleranalyse mit eingefügtem Stacktrace und
     * knapp genug, dass niemand eine Logdatei in die Zeitleiste kippt — dafür
     * gibt es Anhänge (M5).
     */
    public const BODY_LIMIT = 8192;

    /**
     * @return BelongsTo<Issue, $this>
     */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Das schreibende Konto, solange es existiert. Angezeigt wird
     * `author_name` — der bleibt auch danach stehen.
     *
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Wer in diesem Kommentar genannt wurde.
     *
     * @return HasMany<IssueCommentMention, $this>
     */
    public function mentions(): HasMany
    {
        return $this->hasMany(IssueCommentMention::class);
    }

    /**
     * Wurde dieser Kommentar nachträglich geändert?
     */
    public function wasEdited(): bool
    {
        return $this->edited_at !== null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'edited_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
