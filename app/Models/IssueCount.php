<?php

namespace App\Models;

use App\Enums\CountPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ein Zähler je Fehler-Eintrag und Zeitfenster — die Grundlage der
 * Verlaufsgrafik und der Alarm-Bedingungen.
 *
 * Warum überhaupt eine eigene Tabelle, wo die Ereignisse doch alle einzeln
 * daliegen: „wie oft in den letzten 24 Stunden?" wäre über sie ein Zählen von
 * Zeilen, und bei einem Fehler mit einer Million Auftreten ist das keine
 * Abfrage mehr, sondern ein Auftrag. Hier ist dieselbe Frage die Summe über 24
 * Zeilen — und sie bleibt es auch dann, wenn die Ereignisse längst weggeräumt
 * sind.
 *
 * Genau das ist der zweite Grund: die Aufbewahrung der Ereignisse ist kurz, die
 * der Zähler lang. Ein Verlauf über 90 Tage überlebt damit das Aufräumen der
 * Meldungen, aus denen er entstanden ist.
 *
 * @property int $id
 * @property int $issue_id
 * @property CountPeriod $period
 * @property CarbonImmutable $window_start
 * @property int $event_count
 */
class IssueCount extends Model
{
    /**
     * Zählt ein Ereignis in alle Auflösungen seines Zeitpunkts.
     *
     * Zwei Anweisungen je Auflösung, beide sperrfrei:
     *
     *   1. Die Zeile anlegen, falls sie fehlt — ohne Fehler, wenn ein anderer
     *      Arbeiter im selben Augenblick dasselbe tut. Darüber entscheidet der
     *      eindeutige Index und nicht ein vorheriges `exists()`.
     *   2. `event_count = event_count + 1`. Die Datenbank setzt den alten Wert
     *      selbst ein; zwei gleichzeitige Anweisungen ergeben zwei.
     *
     * Der Unterschied zur Vorberechnung der Antwortzeiten
     * ({@see TransactionAggregate::record()}), die dafür sperrt, liegt am
     * Inhalt: dort wird eine **Verteilung** gelesen, geändert und
     * zurückgeschrieben — das geht ohne Sperre nicht. Hier ist es eine Zahl, und
     * für die ist die Sperre unnötig. Sie wäre hier auch besonders teuer: bei
     * einem Ausfall trifft dieselbe Stunde desselben Eintrags **jede**
     * gleichzeitig verarbeitete Meldung.
     */
    public static function record(Issue $issue, CarbonImmutable $occurred): void
    {
        $now = Carbon::now();

        foreach (CountPeriod::cases() as $period) {
            $key = [
                'issue_id' => $issue->id,
                'period' => $period->value,
                'window_start' => $period->windowFor($occurred)->format('Y-m-d H:i:s'),
            ];

            self::query()->insertOrIgnore($key + [
                'event_count' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::update(
                'update '.(new self)->getTable().' set event_count = event_count + 1, updated_at = ? '
                .'where issue_id = ? and period = ? and window_start = ?',
                [$now->format('Y-m-d H:i:s'), $key['issue_id'], $key['period'], $key['window_start']],
            );
        }
    }

    /**
     * Die Zeitreihe eines Eintrags in einer Auflösung, aufsteigend.
     *
     * Aufsteigend und nicht wie sonst „jüngstes zuerst": eine Grafik wird von
     * links nach rechts gelesen, und die Umkehrung wäre an jeder Aufrufstelle
     * nachzuholen.
     *
     * @param  Builder<self>  $query
     */
    public function scopeSeries(Builder $query, CountPeriod $period, CarbonImmutable $since): void
    {
        $query->where('period', $period)
            ->where('window_start', '>=', $period->windowFor($since))
            ->orderBy('window_start');
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
        'period',
        'window_start',
        'event_count',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period' => CountPeriod::class,
            'window_start' => 'immutable_datetime',
            'event_count' => 'integer',
        ];
    }
}
