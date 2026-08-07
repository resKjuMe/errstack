<?php

namespace App\Models;

use App\Enums\AuditAction;
use Database\Factories\AuditLogEntryFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Ein Eintrag des Änderungsprotokolls. Geschrieben wird ausschließlich über
 * App\Support\AuditLog — von Hand erzeugt niemand einen Eintrag.
 *
 * Einträge sind unveränderlich: Ändern und Löschen sind hier abgeschaltet, der
 * einzige zugelassene Weg zum Entfernen ist die Aufbewahrungsfrist
 * (`pruneOlderThan`). Absichern lässt sich das nur so weit, wie die Anwendung
 * reicht — wer direkt auf der Datenbank arbeitet, kommt daran vorbei.
 *
 * @property int $id
 * @property int $organization_id
 * @property int|null $actor_id
 * @property string $actor_name
 * @property string|null $actor_email
 * @property AuditAction $action
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string|null $subject_label
 * @property array<string, array{before: string|null, after: string|null}>|null $changed_values
 * @property string|null $ip_address
 * @property Carbon $created_at
 */
#[Fillable([
    'organization_id',
    'actor_id',
    'actor_name',
    'actor_email',
    'action',
    'subject_type',
    'subject_id',
    'subject_label',
    'changed_values',
    'ip_address',
])]
class AuditLogEntry extends Model
{
    /** @use HasFactory<AuditLogEntryFactory> */
    use HasFactory;

    /** Ein Eintrag wird nie geändert — eine Spalte dafür gibt es deshalb nicht. */
    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Das handelnde Konto, solange es existiert. Für die Anzeige zählt
     * `actor_name` — der bleibt auch nach dem Löschen des Kontos stehen.
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * Entfernt Einträge, die älter als der Stichtag sind. Der einzige Weg, auf
     * dem Einträge verschwinden dürfen — bewusst über den Query Builder, damit
     * die Sperre unten nicht greift, und bewusst als benannte Methode, damit im
     * Code sichtbar bleibt, wer löscht.
     */
    public static function pruneOlderThan(DateTimeInterface $cutoff): int
    {
        return static::query()->where('created_at', '<', $cutoff)->delete();
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Protokolleinträge sind unveränderlich.');
        });

        static::deleting(function (): never {
            throw new LogicException('Protokolleinträge werden nur durch die Aufbewahrungsfrist entfernt.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            'changed_values' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
