<?php

namespace App\Models;

use App\Enums\IssueActivityType;
use App\Support\Issues\IssueActions;
use Database\Factories\IssueActivityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Ein Eintrag im Aktivitätsverlauf eines Fehlers: wer wann was getan hat.
 *
 * Geschrieben wird ausschließlich über {@see IssueActions} — dieselbe Regel wie
 * beim Änderungsprotokoll und aus demselben Grund: ein Verlauf, den jede Stelle
 * nach eigenem Gutdünken füllt, ist keiner. Einträge sind unveränderlich; sie
 * verschwinden nur mit ihrem Projekt.
 *
 * **`issue_id` darf leer sein.** Die beiden Löschvermerke überleben den
 * Eintrag, auf den sie sich beziehen — „gelöscht und künftig verworfen" ist
 * gerade dann die gesuchte Auskunft, wenn der Fehler nicht mehr da ist. Für
 * alle übrigen Arten steht er.
 *
 * @property int $id
 * @property int|null $issue_id
 * @property int $project_id
 * @property int|null $user_id
 * @property string|null $actor_name
 * @property IssueActivityType $type
 * @property array<string, mixed>|null $data
 * @property Carbon $created_at
 */
#[Fillable([
    'issue_id',
    'project_id',
    'user_id',
    'actor_name',
    'type',
    'data',
])]
class IssueActivity extends Model
{
    /** @use HasFactory<IssueActivityFactory> */
    use HasFactory;

    /** Ein Vermerk wird nie geändert — eine Spalte dafür gibt es nicht. */
    public const UPDATED_AT = null;

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
     * Das handelnde Konto, solange es existiert. Angezeigt wird `actor_name` —
     * der bleibt auch nach dem Löschen des Kontos stehen.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Aktivitätsvermerke sind unveränderlich.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => IssueActivityType::class,
            'data' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
