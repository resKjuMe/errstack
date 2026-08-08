<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Was eine Regel über einen bestimmten Fehler schon gemeldet hat.
 *
 * Die Häufigkeitsbegrenzung wohnt hier, und sie ist bewusst **nicht** aus dem
 * Verlauf ({@see IssueAlertTrigger}) errechnet: „gab es in den letzten dreißig
 * Minuten eine Auslösung?" wäre dort eine Bereichsabfrage je Regel und je
 * Ereignis. Hier ist es eine Zeile über einen eindeutigen Index.
 *
 * **Der Anspruch ist eine bedingte Anweisung und keine Sperre**
 * ({@see claim()}). Dieselbe Wahl wie beim Zustandswechsel der Schwellwert-
 * Alarme (A3) und aus demselben Grund: derselbe Fehler ist bei einem Ausfall in
 * jeder gleichzeitig verarbeiteten Meldung derselbe — eine Sperre wäre genau
 * dann der Engpass, an dem die Aufnahme stehen bleibt.
 *
 * @property int $id
 * @property int $issue_alert_rule_id
 * @property int $issue_id
 * @property CarbonImmutable|null $last_notified_at
 * @property int $notified_count
 * @property CarbonImmutable|null $regression_at
 */
class IssueAlertState extends Model
{
    /**
     * Beansprucht das Melderecht für diese Regel und diesen Fehler — genau
     * einmal je Zeitfenster.
     *
     * Zwei Anweisungen, beide sperrfrei: die Zeile anlegen, falls sie fehlt
     * (darüber entscheidet der eindeutige Index und nicht ein vorheriges
     * `exists()`), und sie dann nur dann fortschreiben, wenn die letzte Meldung
     * lange genug her ist. Trifft die zweite keine Zeile, war ein anderer
     * Arbeiter schneller oder die Begrenzung greift — beides heißt „nicht
     * melden", und beides braucht keine weitere Abfrage.
     *
     * Ein noch nicht gemeldeter Rückfall durchbricht die Begrenzung: er ist ein
     * neues Ereignis in der Sache und nicht die Wiederholung eines bereits
     * gemeldeten. Die Ausnahme steht deshalb **in derselben Anweisung** und
     * nicht als vorgelagerte Prüfung — sonst könnten zwei Arbeiter denselben
     * Rückfall gleichzeitig für ungemeldet halten.
     *
     * @param  CarbonImmutable|null  $regressionAt  Der Auflösungszeitpunkt, für den gerade ein
     *                                              Rückfall gemeldet wird — sonst `null`, damit
     *                                              eine Auslösung aus anderem Anlass die Marke
     *                                              nicht verbraucht.
     * @return bool `false`, wenn nicht gemeldet werden darf.
     */
    public static function claim(
        int $ruleId,
        int $issueId,
        int $frequencyMinutes,
        Carbon $now,
        ?CarbonImmutable $regressionAt = null,
    ): bool {
        self::query()->insertOrIgnore([
            'issue_alert_rule_id' => $ruleId,
            'issue_id' => $issueId,
            'notified_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $since = $now->copy()->subMinutes($frequencyMinutes);

        $values = [
            'last_notified_at' => $now,
            'notified_count' => DB::raw('notified_count + 1'),
            'updated_at' => $now,
        ];

        if ($regressionAt !== null) {
            $values['regression_at'] = $regressionAt;
        }

        return self::query()
            ->where('issue_alert_rule_id', $ruleId)
            ->where('issue_id', $issueId)
            ->where(function (Builder $query) use ($since, $regressionAt): void {
                $query->whereNull('last_notified_at')->orWhere('last_notified_at', '<=', $since);

                if ($regressionAt !== null) {
                    $query->orWhereNull('regression_at')->orWhere('regression_at', '<', $regressionAt);
                }
            })
            ->update($values) === 1;
    }

    /**
     * @return BelongsTo<IssueAlertRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(IssueAlertRule::class, 'issue_alert_rule_id');
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
        'issue_alert_rule_id',
        'issue_id',
        'last_notified_at',
        'notified_count',
        'regression_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_notified_at' => 'immutable_datetime',
            'regression_at' => 'immutable_datetime',
            'notified_count' => 'integer',
        ];
    }
}
