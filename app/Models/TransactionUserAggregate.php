<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\TransactionUserAggregateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Die Nutzer-Seite der Vorberechnung: je Nutzer, Transaktion, Umgebung und
 * Zeitfenster eine Zeile.
 *
 * Sie beantwortet die zwei Fragen, die {@see TransactionAggregate} nicht
 * beantworten kann, weil dort bewusst kein Nutzer im Schlüssel steht: „wie viele
 * Nutzer sind betroffen" und „wie vielen davon war es zu langsam". Aus Summen
 * über Transaktionen lassen sich beide nicht herleiten — zehntausend Aufrufe
 * können von zehn Nutzern kommen oder von zehntausend, und für die Frage, wie
 * schlimm ein langsamer Endpunkt ist, macht das den ganzen Unterschied.
 *
 * Gezählt wird über den eindeutigen Schlüssel, nicht über die Einzelmessungen.
 * Die Übersicht liest daraus `COUNT(DISTINCT user_key)` je Transaktion — eine
 * Abfrage über vorverdichtete Zeilen statt ein `COUNT(DISTINCT …)` über
 * Millionen Messungen.
 *
 * @property int $id
 * @property int $project_id
 * @property string $environment
 * @property string $name
 * @property string $op
 * @property CarbonImmutable $window_start
 * @property string $user_key
 * @property string $signature
 * @property int $transaction_count
 * @property int $miserable_count
 */
class TransactionUserAggregate extends Model
{
    /** @use HasFactory<TransactionUserAggregateFactory> */
    use HasFactory;

    /**
     * Länge der gehashten Nutzerkennung in Hex-Zeichen (siehe Migration).
     */
    public const KEY_LENGTH = 40;

    /**
     * Schreibt eine Messung in die Nutzer-Zahlen ihres Zeitfensters fort.
     *
     * Der Ablauf ist derselbe wie in {@see TransactionAggregate::record()} —
     * Zeile anlegen, falls sie fehlt, dann fortschreiben —, aber **ohne Sperre**,
     * und das ist der ganze Grund, warum diese zweite Vorberechnung bezahlbar
     * ist. Dort muss gesperrt werden, weil die Verteilung ein Feld-Baum ist: sie
     * wird gelesen, geändert und zurückgeschrieben, und zwei Arbeiter ohne Sperre
     * überschreiben einander. Hier wird ausschließlich hochgezählt, und das
     * erledigt die Datenbank selbst (`transaction_count + 1`) — zwei gleichzeitige
     * Messungen ergeben zwei, in welcher Reihenfolge sie auch ankommen.
     *
     * Das Anlegen läuft über `insertOrIgnore` gegen den eindeutigen Schlüssel:
     * legen zwei Arbeiter dieselbe Zeile im selben Augenblick an, gewinnt einer
     * und der andere zählt auf dessen Zeile hoch.
     *
     * @param  bool  $miserable  Ob diese Messung über der Unzufriedenheits-Schwelle
     *                           lag ({@see Transaction::miserable()}). Die
     *                           Bewertung fällt hier und wird nicht mehr
     *                           nachgeholt: eine später geänderte Schwelle
     *                           bewertet Altdaten nicht rückwirkend um.
     * @return bool `false`, wenn die Messung keine Nutzerkennung trägt — dann
     *              gibt es hier nichts zu zählen. Das ist kein Fehler, sondern
     *              der Normalfall bei Hintergrundaufgaben und bei SDKs, die
     *              keine Nutzerdaten senden dürfen.
     */
    public static function record(Transaction $transaction, bool $miserable): bool
    {
        $identifier = trim((string) $transaction->user_identifier);

        if ($identifier === '') {
            return false;
        }

        $userKey = self::keyFor($identifier);
        $op = $transaction->op ?? '';

        // Der eindeutige Schlüssel — und nur er. Umgebung, Name, Operation und
        // Nutzer stecken in der Signatur; als eigene Spalten stehen sie für die
        // Auswertung daneben, nicht für das Wiederfinden der Zeile.
        $key = [
            'project_id' => $transaction->project_id,
            'window_start' => $transaction->window(),
            'signature' => self::signatureFor($transaction->environment, $transaction->name, $op, $userKey),
        ];

        self::query()->insertOrIgnore($key + [
            'environment' => $transaction->environment,
            'name' => $transaction->name,
            'op' => $op,
            'user_key' => $userKey,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $increments = ['transaction_count' => DB::raw('transaction_count + 1')];

        if ($miserable) {
            $increments['miserable_count'] = DB::raw('miserable_count + 1');
        }

        self::query()->where($key)->update($increments);

        return true;
    }

    /**
     * Die Nutzerkennung in der Form, in der sie hier steht.
     *
     * Gehasht, weil in dieser Tabelle nur gezählt und nie angezeigt wird. Wer
     * betroffen ist, steht an der Transaktion; hier genügt ein Wert, der sich mit
     * sich selbst vergleichen lässt — und der bei einem Blick in die Tabelle
     * niemanden verrät.
     */
    public static function keyFor(string $identifier): string
    {
        return substr(hash('sha256', $identifier), 0, self::KEY_LENGTH);
    }

    /**
     * Der eindeutige Schlüssel einer Zeile als ein Wert.
     *
     * Die Trennzeichen sind Nullbytes und keine Punkte: ein Trennzeichen, das im
     * Namen vorkommen kann, macht aus zwei verschiedenen Schlüsseln denselben —
     * `("a", "b.c")` und `("a.b", "c")`. In einem Transaktionsnamen darf alles
     * stehen, nur kein Nullbyte.
     */
    public static function signatureFor(string $environment, string $name, string $op, string $userKey): string
    {
        return hash('sha256', implode("\0", [$environment, $name, $op, $userKey]));
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'window_start' => 'immutable_datetime',
            'transaction_count' => 'integer',
            'miserable_count' => 'integer',
        ];
    }
}
