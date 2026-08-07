<?php

namespace App\Models;

use App\Support\Performance\DurationHistogram;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Die Vorberechnung: je Transaktionsname, Operation, Umgebung und Zeitfenster
 * eine Zeile mit Anzahl, Summe, Grenzwerten und Verteilung.
 *
 * Ohne diese Tabelle müsste die Performance-Übersicht über die Einzelmessungen
 * rechnen. Bei einer Anwendung mit 100 Aufrufen je Sekunde sind das 260 Millionen
 * Zeilen im Monat — für eine Seite, die in einer Sekunde erscheinen soll, ist das
 * nicht zu schaffen. Mit einem Fenster je Minute stehen für denselben Monat je
 * Transaktionsname 44.000 Zeilen da, und die Übersicht summiert sie.
 *
 * Die Zusammensetzung des Schlüssels ist die eigentliche Entscheidung: alles,
 * wonach die Übersicht filtert, muss darin stehen — sonst wäre die Zeile für
 * einen Filter nicht zu verwenden. Alles andere darf **nicht** darin stehen, denn
 * jedes weitere Merkmal vervielfacht die Zeilen. Deshalb Name, Operation und
 * Umgebung — und nicht Version oder Nutzer.
 *
 * **Zwei Anzahlen, weil die Stichprobe zwei Fragen offen lässt.**
 * `transaction_count` zählt die gespeicherten Messungen und sagt damit, worauf
 * die Verteilung dieses Fensters beruht; `extrapolated_count` ist die daraus
 * geschätzte Zahl der **tatsächlichen** Aufrufe (I9). Ohne Stichprobe laufen
 * beide gleich. Antwortzeiten und Fehlerrate werden dagegen **nicht**
 * hochgerechnet — Verteilungen und Anteile lassen sich aus einer Stichprobe
 * unverzerrt schätzen, Anzahlen nicht.
 *
 * @property int $id
 * @property int $project_id
 * @property string $environment
 * @property string $name
 * @property string $op
 * @property CarbonImmutable $window_start
 * @property int $transaction_count
 * @property float $extrapolated_count
 * @property int $failure_count
 * @property int $duration_sum_us
 * @property int|null $duration_min_us
 * @property int|null $duration_max_us
 * @property array<int, int>|null $duration_histogram
 */
class TransactionAggregate extends Model
{
    /**
     * Schreibt eine Messung in ihr Zeitfenster fort.
     *
     * Der Ablauf ist dreiteilig und jeder Teil hat seinen Grund:
     *
     *   1. Zeile anlegen, falls sie fehlt — ohne Fehler, wenn ein anderer
     *      Arbeiter im selben Augenblick dasselbe tut (`insertOrIgnore` gegen
     *      den eindeutigen Schlüssel).
     *   2. Zeile sperren und lesen.
     *   3. Werte fortschreiben und speichern.
     *
     * Die Sperre ist nicht zu vermeiden: Anzahl und Summe ließen sich mit
     * `count = count + 1` auch ohne sie hochzählen, die **Verteilung** aber
     * nicht — sie ist ein Feld-Baum, der gelesen, geändert und zurückgeschrieben
     * werden muss. Zwei Arbeiter ohne Sperre lesen dieselbe Verteilung, und die
     * zweite Messung überschreibt die erste: die Anzahl stünde dann auf 2, die
     * Verteilung enthielte einen Wert. Genau dieser Widerspruch macht jede
     * darauf gerechnete Kennzahl unbrauchbar.
     *
     * Der Preis ist Wartezeit auf einer heißen Zeile — dieselbe Minute, dieselbe
     * Transaktion. Der Ausweg ist nicht die schwächere Zusage, sondern das
     * Zusammenfassen mehrerer Messungen vor dem Schreiben; das gehört zur
     * Härtung der Aufnahme (O12), wenn sich zeigt, wo die Grenze liegt.
     */
    public static function record(Transaction $transaction): self
    {
        $key = [
            'project_id' => $transaction->project_id,
            'environment' => $transaction->environment,
            'name' => $transaction->name,
            // Die leere Zeichenkette statt `null`, damit der eindeutige
            // Schlüssel greift: zwei `NULL` gelten in MySQL als verschieden, und
            // die Zeile „ohne Operation" entstünde bei jeder Messung neu.
            'op' => $transaction->op ?? '',
            'window_start' => $transaction->window(),
        ];

        return DB::transaction(function () use ($key, $transaction): self {
            self::query()->insertOrIgnore($key + [
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $aggregate = self::query()
                ->where($key)
                ->lockForUpdate()
                ->firstOrFail();

            $aggregate->add($transaction->duration_us, $transaction->failed(), $transaction->sampleWeight());
            $aggregate->save();

            return $aggregate;
        });
    }

    /**
     * Nimmt eine Messung in die Zahlen auf.
     *
     * Getrennt von {@see record()}, damit sich das Fortschreiben ohne Datenbank
     * prüfen lässt — die Rechnung ist der Teil, an dem sich Fehler verstecken,
     * nicht das Sperren.
     *
     * @param  float  $weight  Für wie viele Aufrufe die Messung steht
     *                         ({@see Transaction::sampleWeight()}). Ohne
     *                         Stichprobe 1 — dann laufen tatsächliche und
     *                         hochgerechnete Anzahl gleich.
     */
    public function add(int $durationUs, bool $failed, float $weight = 1.0): void
    {
        $this->transaction_count = $this->transaction_count + 1;
        // Die hochgerechnete Anzahl **neben** der tatsächlichen und nicht an
        // ihrer Stelle: an der tatsächlichen hängt die Aussagekraft der
        // Verteilung. Eine p95 aus drei Messungen ist eine andere Auskunft als
        // eine aus dreitausend, und diese Unterscheidung wäre verloren, wenn hier
        // nur noch eine Zahl stünde.
        $this->extrapolated_count = $this->extrapolated_count + $weight;
        $this->failure_count = $this->failure_count + ($failed ? 1 : 0);
        $this->duration_sum_us = $this->duration_sum_us + $durationUs;

        $this->duration_min_us = $this->duration_min_us === null
            ? $durationUs
            : min($this->duration_min_us, $durationUs);

        $this->duration_max_us = $this->duration_max_us === null
            ? $durationUs
            : max($this->duration_max_us, $durationUs);

        $histogram = $this->histogram();
        $histogram->add($durationUs);

        $this->duration_histogram = $histogram->toArray();
    }

    /**
     * Die abgelegte Verteilung als Rechenobjekt.
     */
    public function histogram(): DurationHistogram
    {
        return DurationHistogram::fromStored($this->duration_histogram);
    }

    /**
     * Die mittlere Antwortzeit dieses Fensters in Mikrosekunden.
     */
    public function averageUs(): ?float
    {
        return $this->transaction_count === 0
            ? null
            : $this->duration_sum_us / $this->transaction_count;
    }

    /**
     * Der Anteil nicht erfolgreicher Aufrufe, zwischen 0 und 1.
     */
    public function failureRate(): ?float
    {
        return $this->transaction_count === 0
            ? null
            : $this->failure_count / $this->transaction_count;
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
            // `float` und nicht `decimal:4`: die Zahl wird summiert und
            // fortgeschrieben, `decimal` liefert eine Zeichenkette. Die Spalte
            // bleibt `decimal`, damit sich die Summen mehrerer Fenster ohne
            // wachsenden Rundungsfehler bilden lassen.
            'extrapolated_count' => 'float',
            'failure_count' => 'integer',
            'duration_sum_us' => 'integer',
            'duration_min_us' => 'integer',
            'duration_max_us' => 'integer',
            'duration_histogram' => 'array',
        ];
    }
}
