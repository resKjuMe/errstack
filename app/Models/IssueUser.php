<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ein Betroffener eines Fehler-Eintrags — als Streuwert, nicht als Person.
 *
 * Die Frage, die diese Tabelle beantwortet, ist eine Zahl: einer betroffen oder
 * zehntausend. Das ist derselbe Zähler und sind zwei völlig verschiedene Lagen,
 * und ohne ihn hat jeder Fehler dasselbe Gewicht.
 *
 * Gespeichert wird deshalb nur, was zum **Unterscheiden** nötig ist: der
 * Streuwert der Kennung. Wer wirklich betroffen war, steht am Ereignis — dort,
 * wo das Scrubbing (I7) darüber entschieden hat, ob es überhaupt gespeichert
 * werden darf, und wo die Aufbewahrung es wieder wegräumt. Ein zweites Register
 * mit Klartext-Kennungen daneben würde beide Zusagen aushebeln.
 *
 * @property int $id
 * @property int $issue_id
 * @property string $user_key
 * @property Carbon $first_seen
 */
class IssueUser extends Model
{
    /**
     * Nimmt den Betroffenen eines Ereignisses auf und zählt ihn, falls er neu ist.
     *
     * Das Zählverfahren ist der eindeutige Index und nicht eine Abfrage: „gibt
     * es den schon?" wäre nur eine Momentaufnahme, und zwei Arbeiter mit
     * demselben Nutzer bekämen beide „nein". Stattdessen versuchen beide, die
     * Zeile einzufügen — genau einer schafft es, und genau der zählt hoch.
     *
     * `users_seen` steht am Eintrag und wird hier fortgeschrieben, statt bei
     * jeder Anzeige über diese Tabelle zu zählen: die Fehlerliste zeigt die Zahl
     * für jede Zeile, und das wäre je Seitenaufruf ein Zählen über alle
     * Betroffenen aller angezeigten Einträge.
     *
     * @return bool `true`, wenn dieser Nutzer neu war.
     */
    public static function record(Issue $issue, Event $event): bool
    {
        $key = self::keyFor($event);

        if ($key === null) {
            return false;
        }

        $now = Carbon::now();

        $inserted = self::query()->insertOrIgnore([
            'issue_id' => $issue->id,
            'user_key' => $key,
            'first_seen' => $event->occurred_at,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted === 0) {
            return false;
        }

        DB::update(
            'update '.(new Issue)->getTable().' set users_seen = users_seen + 1 where id = ?',
            [$issue->id],
        );

        return true;
    }

    /**
     * Der Streuwert, an dem sich Betroffene unterscheiden lassen.
     *
     * Die Rangfolge der Felder ist die Reihenfolge ihrer Beständigkeit: die
     * Kennung der Anwendung ist über Sitzungen und Geräte hinweg dieselbe, der
     * Anmeldename fast immer, die Adresse meistens. Die IP-Adresse steht zuletzt
     * und ist bewusst der schlechteste Fall — hinter einem Firmenanschluss sind
     * hundert Betroffene einer, in einem Mobilfunknetz ist einer an einem
     * Vormittag drei. Sie ist trotzdem besser als nichts: ohne sie hätte eine
     * Anwendung ohne Anmeldung überhaupt keine Zahl.
     *
     * Kein Wert ist keine Schätzung: eine Meldung ohne jede Angabe wird **nicht**
     * gezählt. Sie mit einem Ersatzwert einzureihen hieße, alle anonymen
     * Ereignisse eines Eintrags zu einem einzigen Betroffenen zu machen — oder,
     * mit einem zufälligen Wert, zu je einem eigenen. Beides wäre eine erfundene
     * Zahl an einer Stelle, an der eine echte erwartet wird.
     */
    public static function keyFor(Event $event): ?string
    {
        $user = $event->user ?? [];

        foreach (['id', 'username', 'email', 'ip_address'] as $field) {
            $value = $user[$field] ?? null;

            if (is_string($value) && trim($value) !== '') {
                // Das Feld gehört mit in den Streuwert: sonst wäre der Nutzer
                // mit der Kennung „42" derselbe wie der mit dem Anmeldenamen
                // „42".
                return md5($field.'|'.trim($value));
            }
        }

        return null;
    }

    /**
     * @return BelongsTo<Issue, $this>
     */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'issue_id',
        'user_key',
        'first_seen',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_seen' => 'datetime',
        ];
    }
}
