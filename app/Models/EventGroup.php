<?php

namespace App\Models;

use App\Enums\GroupingSource;
use App\Support\Ingest\Grouping\Fingerprint;
use Database\Factories\EventGroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Eine Gruppe gleichartiger Meldungen: ein Fingerabdruck in einem Projekt.
 *
 * Das ist die Antwort auf die Aufgabe „zehntausend gleiche Abstürze ergeben
 * einen Eintrag". Was die Gruppe **nicht** ist: der Fehler-Eintrag, den jemand
 * ansieht, zuweist und schließt. Dort stehen Zähler, Zeitpunkte, Zustand und
 * Priorität — er kommt mit I6 und wird mehrere Gruppen umfassen können, sobald
 * S9 das Zusammenführen von Hand bringt.
 *
 * Die Trennung ist der Grund, warum hier so wenig steht. Läge der Zähler an der
 * Gruppe, wäre jedes Zusammenführen ein Umrechnen und jedes Auftrennen ein
 * Verlust — und beides muss verlustfrei sein.
 *
 * @property int $id
 * @property int $project_id
 * @property int|null $issue_id
 * @property string $fingerprint
 * @property GroupingSource $source
 * @property list<array{name: string, value: string}>|null $components
 * @property int|null $fingerprint_rule_id
 */
class EventGroup extends Model
{
    /** @use HasFactory<EventGroupFactory> */
    use HasFactory;

    /**
     * Findet die Gruppe zu einem Fingerabdruck oder legt sie an.
     *
     * Der Ablauf ist der einer Wettlaufsituation und nicht der eines Nachschlags:
     * bei einer Fehlerflut laufen mehrere Arbeiter gleichzeitig auf denselben,
     * gerade zum ersten Mal auftretenden Fehler. Ein `exists()` vor dem Einfügen
     * wäre nur eine Momentaufnahme; entscheiden muss der eindeutige Index über
     * `project_id` und `fingerprint`, und zwar für alle Arbeiter gleichzeitig.
     *
     * Der Verstoß ist deshalb kein Fehler, sondern die Antwort „hat schon jemand
     * angelegt" — dann wird die vorhandene Gruppe genommen.
     *
     * **Die Begründung wird nicht nachgetragen.** Sie stammt vom ersten
     * Ereignis, und das ist die richtige Wahl: sie erklärt, wie die Gruppe
     * entstanden ist. Wie ein späteres Ereignis in sie hineingekommen ist, steht
     * an diesem Ereignis.
     */
    public static function forFingerprint(int $projectId, Fingerprint $fingerprint): self
    {
        $existing = self::query()
            ->where('project_id', $projectId)
            ->where('fingerprint', $fingerprint->hash)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return self::query()->create([
                'project_id' => $projectId,
                'fingerprint' => $fingerprint->hash,
                'source' => $fingerprint->source,
                'components' => $fingerprint->toArray()['components'],
                'fingerprint_rule_id' => $fingerprint->ruleId,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Ein anderer Arbeiter war schneller. Seine Gruppe ist unsere
            // Gruppe — beide haben denselben Fingerabdruck gerechnet, sonst
            // stünden sie hier nicht.
            return self::query()
                ->where('project_id', $projectId)
                ->where('fingerprint', $fingerprint->hash)
                ->sole();
        }
    }

    /**
     * @return HasMany<Event, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Der Fehler-Eintrag, zu dem diese Gruppe gehört.
     *
     * `null`, solange die Aggregation nicht gelaufen ist — bei einer Gruppe aus
     * der Zeit vor I6 und bei jeder, deren Kette vor dem Zählen abgebrochen ist.
     * Ab S9 zeigen mehrere Gruppen auf denselben Eintrag; deshalb steht der
     * Verweis hier und nicht dort.
     *
     * @return BelongsTo<Issue, $this>
     */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    /**
     * Die Regel, die diese Gruppe hervorgebracht hat — falls es eine war.
     *
     * @return BelongsTo<FingerprintRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(FingerprintRule::class, 'fingerprint_rule_id');
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'fingerprint',
        'source',
        'components',
        'fingerprint_rule_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => GroupingSource::class,
            'components' => 'array',
        ];
    }
}
