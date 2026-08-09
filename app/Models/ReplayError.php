<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Die Verknüpfung zwischen einer Aufzeichnung und einem Fehler — in beide
 * Richtungen dieselbe Zeile.
 *
 * Sie entsteht von zwei Seiten, und keine der beiden ist die verlässlichere:
 *
 *   Von der Aufzeichnung — deren Kopfdaten führen die Nummern der Fehler auf,
 *   die während der Sitzung passiert sind.
 *   Vom Fehler — dessen Meldung trägt die Nummer der laufenden Aufzeichnung
 *   unter `contexts.replay`.
 *
 * Beide Wege werden gegangen ({@see App\Support\Ingest\Processing\Steps\RecordReplay},
 * {@see App\Support\Ingest\Processing\Steps\LinkEventReplay}), weil beide Lücken
 * haben: die Kopfdaten einer noch laufenden Sitzung kennen den Fehler von eben
 * noch nicht, und ein Fehler, den das SDK ohne Replay-Kontext meldet, kennt
 * seine Sitzung nicht. Zusammen decken sie ab, was einzeln durchfiele.
 *
 * Der Verweis auf den Fehler ist Text und kein Fremdschlüssel — warum, steht in
 * der Migration. Kurz: die Reihenfolge ist offen, die Aufbewahrungsfristen sind
 * verschieden, und die beiden Bestände sollen getrennt löschbar bleiben.
 *
 * @property int $id
 * @property int $replay_id
 * @property int $project_id
 * @property string $event_id
 * @property CarbonImmutable|null $occurred_at
 */
class ReplayError extends Model
{
    /**
     * @var string
     */
    protected $dateFormat = 'Y-m-d H:i:s.v';

    /**
     * Hält fest, dass dieser Fehler in dieser Sitzung passiert ist — und schreibt
     * den Zähler der Sitzung fort.
     *
     * Der Zähler wird **nur beim ersten Mal** erhöht. Das ist der eigentliche
     * Grund, warum diese Methode existiert und der Aufrufer nicht selbst
     * `create()` ruft: dieselbe Verknüpfung wird regelmäßig zweimal gemeldet —
     * einmal von der Aufzeichnung, einmal vom Fehler —, und eine Sitzung mit
     * einem Fehler stünde sonst mit zweien in der Liste.
     *
     * @return bool Ob die Verknüpfung neu war.
     */
    public static function link(Replay $replay, string $eventId, ?CarbonImmutable $occurredAt = null): bool
    {
        try {
            $link = new self;
            $link->replay_id = $replay->id;
            $link->project_id = $replay->project_id;
            $link->event_id = $eventId;
            $link->occurred_at = $occurredAt;
            $link->save();
        } catch (UniqueConstraintViolationException) {
            // Beide Seiten haben denselben Fehler gemeldet, und zwar gleichzeitig.
            // Der eindeutige Index entscheidet; für den Verlierer ist das kein
            // Fehlschlag, sondern die Bestätigung, dass die Verknüpfung steht.
            return false;
        }

        // `increment()` und nicht `error_count + 1` im Speicher: zwei Fehler
        // derselben Sitzung werden von zwei Arbeitern gleichzeitig verarbeitet,
        // und ein gelesener Zählwert wäre in dem Moment schon veraltet.
        $replay->increment('error_count');

        return true;
    }

    /**
     * @return BelongsTo<Replay, $this>
     */
    public function replay(): BelongsTo
    {
        return $this->belongsTo(Replay::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
