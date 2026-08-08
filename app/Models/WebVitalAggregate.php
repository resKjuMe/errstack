<?php

namespace App\Models;

use App\Enums\VitalRating;
use App\Enums\WebVital;
use App\Support\Performance\Vitals\VitalHistogram;
use App\Support\Performance\Vitals\VitalReading;
use App\Support\Performance\Vitals\VitalSummary;
use Carbon\CarbonImmutable;
use Database\Factories\WebVitalAggregateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Die Vorberechnung der Browser-Messwerte: je Seite, Messwert, Umgebung und
 * Zeitfenster eine Zeile mit Anzahl, Bewertung, Summe, Grenzwerten und
 * Verteilung.
 *
 * **Zwei Arten von Zahlen stehen hier nebeneinander, und der Unterschied ist
 * der wichtigste Gedanke dieser Klasse.**
 *
 * Die *Bewertung* — wie viele Messungen gut, mäßig und schlecht waren — wird mit
 * dem **genauen** Wert jeder einzelnen Messung gezählt. Sie ist damit exakt, und
 * zwar für jeden Zeitraum: drei Zähler lassen sich addieren. Genau daran hängt,
 * dass eine Seite mit einem LCP von 2,49 s als gut gilt und nicht als mäßig.
 *
 * Die *Verteilung* ist eine Näherung ({@see VitalHistogram}) und liefert die
 * Zahl, die neben der Bewertung steht. Sie ist nötig, weil sich ein Perzentil
 * nicht addieren lässt — aus den p75 von sechzig Minuten wird nicht das p75 der
 * Stunde.
 *
 * Beides zusammen ergibt eine Auskunft, die weder falsch noch nutzlos ist: die
 * Einordnung stimmt genau, die Zahl daneben auf ein paar Prozent — und sie wird
 * an der exakten Einordnung noch zurechtgerückt
 * ({@see VitalSummary::fromTotals()}).
 *
 * @property int $id
 * @property int $project_id
 * @property string $environment
 * @property string $name
 * @property string $vital
 * @property CarbonImmutable $window_start
 * @property int $measurement_count
 * @property int $good_count
 * @property int $needs_improvement_count
 * @property int $poor_count
 * @property int $value_sum
 * @property int|null $value_min
 * @property int|null $value_max
 * @property array<int, int>|null $value_histogram
 */
class WebVitalAggregate extends Model
{
    /** @use HasFactory<WebVitalAggregateFactory> */
    use HasFactory;

    /**
     * Wie lang der Name eines Messwerts in der Spalte werden darf.
     *
     * Großzügig gegenüber den sechs bekannten (`lcp` … `ttfb`), damit ein später
     * ergänzter Messwert der Spezifikation keine Migration braucht.
     */
    public const VITAL_LIMIT = 16;

    /**
     * Schreibt alle Browser-Messwerte einer Transaktion in ihre Zeitfenster
     * fort.
     *
     * Aufgerufen an derselben Stelle wie die Vorberechnung der Antwortzeiten und
     * unter denselben Bedingungen: **einmal je Messung**. Ein zweiter Durchlauf
     * derselben Rohdaten — nach einem gescheiterten Job der ausdrücklich
     * vorgesehene Fall — darf die Zähler nicht ein zweites Mal erhöhen.
     *
     * Eine Datenbank-Transaktion je Messwert und nicht eine für alle: sie sperrt
     * jeweils genau die Zeile, die fortgeschrieben wird. Eine gemeinsame Sperre
     * über bis zu sechs Zeilen hielte diese länger als nötig und brächte die
     * Möglichkeit einer Verklemmung, wenn zwei Arbeiter die Messwerte in
     * verschiedener Reihenfolge abarbeiteten.
     *
     * @return int Wie viele Messwerte fortgeschrieben wurden.
     */
    public static function record(Transaction $transaction): int
    {
        $readings = VitalReading::all($transaction->measurements);

        foreach ($readings as $reading) {
            self::recordReading($transaction, $reading);
        }

        return count($readings);
    }

    /**
     * Schreibt einen einzelnen Messwert fort.
     *
     * Derselbe dreiteilige Ablauf wie bei den Antwortzeiten — anlegen, sperren,
     * fortschreiben — und aus demselben Grund: die Zähler ließen sich auch ohne
     * Sperre hochzählen, die Verteilung nicht. Sie ist ein Feld-Baum, der
     * gelesen, geändert und zurückgeschrieben werden muss; zwei Arbeiter ohne
     * Sperre lesen dieselbe Verteilung, und die zweite Messung überschriebe die
     * erste.
     */
    private static function recordReading(Transaction $transaction, VitalReading $reading): self
    {
        $key = [
            'project_id' => $transaction->project_id,
            'environment' => $transaction->environment,
            'name' => $transaction->name,
            'vital' => $reading->vital->value,
            'window_start' => $transaction->window(),
        ];

        return DB::transaction(function () use ($key, $reading): self {
            self::query()->insertOrIgnore($key + [
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $aggregate = self::query()
                ->where($key)
                ->lockForUpdate()
                ->firstOrFail();

            $aggregate->add($reading->value, $reading->rating());
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
     */
    public function add(int $value, VitalRating $rating): void
    {
        $this->measurement_count = $this->measurement_count + 1;

        $column = $rating->column();
        $this->{$column} = $this->{$column} + 1;

        $this->value_sum = $this->value_sum + $value;

        $this->value_min = $this->value_min === null
            ? $value
            : min($this->value_min, $value);

        $this->value_max = $this->value_max === null
            ? $value
            : max($this->value_max, $value);

        $histogram = $this->histogram();
        $histogram->add($value);

        $this->value_histogram = $histogram->toArray();
    }

    /**
     * Die abgelegte Verteilung als Rechenobjekt.
     */
    public function histogram(): VitalHistogram
    {
        return VitalHistogram::fromStored($this->value_histogram);
    }

    /**
     * Der Messwert dieser Zeile, oder `null`, wenn in der Spalte etwas steht,
     * das zu keinem bekannten gehört.
     *
     * Der Fall ist nicht theoretisch: wird ein Messwert später aus der
     * Auswertung genommen, stehen seine Zeilen noch bis zum Ende der
     * Aufbewahrung da. Sie sollen dann übergangen werden und nicht die Anzeige
     * anhalten.
     */
    public function vital(): ?WebVital
    {
        return WebVital::tryFrom($this->vital);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Wie die Feld-Bäume dieses Models nach JSON geschrieben werden.
     *
     * `JSON_FORCE_OBJECT` für die Verteilung, aus demselben Grund wie bei den
     * Antwortzeiten: sind die Klassen lückenlos und beginnen bei null, schriebe
     * `json_encode` eine JSON-Liste statt eines Objekts, und dieselbe Klasse
     * stünde mal unter `$."0"` und mal unter `$[0]`. Die Übersicht legt die
     * Verteilungen eines Zeitraums **in der Datenbank** zusammen und braucht
     * dafür je Klasse genau einen Pfad.
     *
     * @param  string  $key
     */
    protected function getJsonCastFlags($key): int
    {
        return parent::getJsonCastFlags($key) | JSON_FORCE_OBJECT;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'window_start' => 'immutable_datetime',
            'measurement_count' => 'integer',
            'good_count' => 'integer',
            'needs_improvement_count' => 'integer',
            'poor_count' => 'integer',
            'value_sum' => 'integer',
            'value_min' => 'integer',
            'value_max' => 'integer',
            'value_histogram' => 'array',
        ];
    }
}
