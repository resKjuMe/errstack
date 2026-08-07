<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Der Anspruch auf die Auswertung einer Ereignis-Nummer.
 *
 * Eine Zeile hier heißt: diese Nummer wird in diesem Projekt von genau dieser
 * Meldung ausgewertet. Wer sie nicht bekommt, ist ein Doppel.
 *
 * @property int $id
 * @property int $project_id
 * @property string $event_id
 * @property string $type
 * @property int $ingest_payload_id
 */
class ProcessedEvent extends Model
{
    /**
     * Beansprucht die Auswertung für diese Meldung.
     *
     * Erst schreiben, dann fragen — und nicht umgekehrt: ein vorheriges
     * `exists()` wäre nur eine Momentaufnahme, zwischen der und dem Einfügen
     * ein zweiter Arbeiter dieselbe Antwort bekäme. Über den eindeutigen Index
     * entscheidet stattdessen die Datenbank, und zwar für beide gleichzeitig.
     *
     * Der Verstoß ist hier deshalb kein Fehler, sondern die Antwort „nein".
     *
     * @return bool `true`, wenn diese Meldung ausgewertet werden darf.
     */
    public static function claim(IngestPayload $payload): bool
    {
        try {
            self::query()->create([
                'project_id' => $payload->project_id,
                'event_id' => $payload->event_id,
                'type' => $payload->type->value,
                'ingest_payload_id' => $payload->id,
            ]);

            return true;
        } catch (UniqueConstraintViolationException) {
            // Der Anspruch besteht schon. Gehört er dieser Meldung, ist es ein
            // erneuter Anlauf desselben Jobs nach einem Fehlschlag — der darf
            // weiterarbeiten, sonst hielte sich jeder Wiederholungsversuch für
            // ein Doppel seiner selbst und die Meldung käme nie durch.
            return self::holderOf($payload) === $payload->id;
        }
    }

    /**
     * Gibt den Anspruch dieser Meldung wieder frei.
     *
     * Nötig, wenn die Auswertung endgültig gescheitert ist: sonst bliebe die
     * Nummer für immer belegt, und eine erneute Zustellung derselben Meldung —
     * die zweite Chance, die ein SDK von sich aus anbietet — würde als Doppel
     * abgetan, obwohl nie etwas ausgewertet wurde.
     */
    public static function release(IngestPayload $payload): void
    {
        self::query()
            ->where('ingest_payload_id', $payload->id)
            ->delete();
    }

    /**
     * Welche Meldung den Anspruch auf diese Nummer hält, falls jemand ihn hält.
     */
    private static function holderOf(IngestPayload $payload): ?int
    {
        $holder = self::query()
            ->where('project_id', $payload->project_id)
            ->where('event_id', $payload->event_id)
            ->where('type', $payload->type->value)
            ->value('ingest_payload_id');

        return is_int($holder) ? $holder : null;
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
        'event_id',
        'type',
        'ingest_payload_id',
    ];
}
