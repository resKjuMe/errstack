<?php

namespace App\Support\Performance\Vitals;

use App\Enums\VitalRating;
use App\Enums\WebVital;
use App\Support\Performance\TransactionTrend;

/**
 * Ein Messwert über einen Zeitraum: wie oft gemessen, wie verteilt, wie
 * bewertet, wie im Vergleich zu vorher.
 *
 * **Die Bewertung entsteht nicht aus der angezeigten Zahl.** Das ist die
 * eigentliche Entscheidung dieser Klasse und der Grund, warum sie zwischen der
 * Vorberechnung und der Anzeige steht.
 *
 * Der naheliegende Weg wäre: p75 aus der Verteilung lesen, gegen die Schwelle
 * halten, fertig. Er ist falsch, weil die Verteilung eine Näherung ist — knapp
 * an der Schwelle entschiede eine Rundung darüber, ob eine Seite als gut oder
 * als mäßig gilt. Stattdessen wird die Klasse aus den **exakten** Zählern
 * gesucht ({@see ratingFrom()}): dort ist jede Messung mit ihrem genauen Wert
 * eingeordnet worden, und die Klasse, in die das p75 fällt, lässt sich daraus
 * ohne jeden Fehler bestimmen.
 *
 * Umgekehrt weiß man damit auch etwas über die **Zahl**: liegt das p75
 * nachweislich in der Klasse „gut", dann ist es höchstens so groß wie deren
 * Obergrenze. Genau darauf wird der Schätzwert der Verteilung zurechtgerückt
 * ({@see clamp()}) — er wird dadurch nie ungenauer, sondern immer genauer, und
 * die Seite zeigt nie eine Zahl, die ihrer eigenen Bewertung widerspricht.
 */
final class VitalSummary
{
    /**
     * @param  int  $count  Gemessene Ladevorgänge mit diesem Messwert.
     * @param  array<string, int>  $ratings  Bewertung → Anzahl, alle drei Klassen
     *                                       immer enthalten.
     * @param  int|null  $value  Das p75 in Millionsteln, oder `null` ohne
     *                           Messung.
     * @param  int|null  $averageValue  Der Mittelwert in Millionsteln.
     */
    private function __construct(
        public readonly WebVital $vital,
        public readonly int $count,
        public readonly array $ratings,
        public readonly ?VitalRating $rating,
        public readonly ?int $value,
        public readonly ?int $averageValue,
        public readonly ?int $minValue,
        public readonly ?int $maxValue,
        public readonly VitalHistogram $histogram,
        public readonly TransactionTrend $trend,
    ) {}

    /**
     * Ein Messwert ohne eine einzige Messung im Zeitraum.
     *
     * Kein `null` und keine leere Zeile: „keine Daten" ist eine Auskunft, und
     * sie muss in der Anzeige von „gemessen, Ergebnis null" zu unterscheiden
     * sein. Genau das ist der Unterschied zwischen einer Seite, für die das SDK
     * nichts meldet, und einer, die schnell ist.
     */
    public static function empty(WebVital $vital): self
    {
        return new self(
            vital: $vital,
            count: 0,
            ratings: self::zeroRatings(),
            rating: null,
            value: null,
            averageValue: null,
            minValue: null,
            maxValue: null,
            histogram: VitalHistogram::empty(),
            trend: TransactionTrend::unknown(),
        );
    }

    /**
     * Baut die Zusammenfassung aus einer zusammengelegten Ergebniszeile.
     *
     * @param  array<string, mixed>  $totals  Summen über die Zeitfenster des
     *                                        Zeitraums.
     * @param  array<string, mixed>|null  $previous  Dieselben Summen des
     *                                               Vorzeitraums, für den Trend.
     */
    public static function fromTotals(WebVital $vital, array $totals, ?array $previous = null): self
    {
        $count = (int) ($totals['measurement_count'] ?? 0);

        if ($count === 0) {
            return self::empty($vital);
        }

        $ratings = self::ratingsFrom($totals);
        $rating = self::ratingFrom($ratings, $count);
        $histogram = VitalHistogram::fromRowSums($totals);

        $sum = (int) ($totals['value_sum'] ?? 0);

        return new self(
            vital: $vital,
            count: $count,
            ratings: $ratings,
            rating: $rating,
            value: self::clamp($vital, $histogram->percentile(WebVital::PERCENTILE), $rating),
            averageValue: (int) round($sum / $count),
            minValue: isset($totals['value_min']) ? (int) $totals['value_min'] : null,
            maxValue: isset($totals['value_max']) ? (int) $totals['value_max'] : null,
            histogram: $histogram,
            trend: self::trend($vital, $histogram, $count, $previous),
        );
    }

    /**
     * Der Anteil der Messungen in einer Bewertungsklasse, zwischen 0 und 1.
     */
    public function share(VitalRating $rating): float
    {
        return $this->count === 0
            ? 0.0
            : ($this->ratings[$rating->value] ?? 0) / $this->count;
    }

    /**
     * Die drei Anteile für den Verteilungsbalken.
     *
     * Gerundet, weil sie sonst mit fünfzehn Nachkommastellen in der Nutzlast
     * stünden — vier Stellen sind zehntel Prozent und damit genauer, als ein
     * Balken zeichnen kann.
     *
     * @return array<string, float>
     */
    public function shares(): array
    {
        $shares = [];

        foreach (VitalRating::ordered() as $rating) {
            $shares[$rating->value] = round($this->share($rating), 4);
        }

        return $shares;
    }

    /**
     * Wie viele Ladevorgänge nicht in Ordnung waren, schlechte doppelt gezählt.
     *
     * Die Zahl, nach der die Übersicht sortiert ({@see VitalRating::weight()}).
     * Sie steht hier und nicht in der Übersicht, damit „was ist an dieser Seite
     * schlecht" und „welche Seite ist die schlechteste" dieselbe Rechnung
     * benutzen.
     */
    public function impact(): int
    {
        $impact = 0;

        foreach (VitalRating::ordered() as $rating) {
            $impact += ($this->ratings[$rating->value] ?? 0) * $rating->weight();
        }

        return $impact;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'vital' => $this->vital->value,
            'label' => $this->vital->label(),
            'description' => $this->vital->description(),
            // Damit die Anzeige weiß, ob sie „2,4 s" oder „0,12" schreiben muss.
            'score' => $this->vital->isScore(),
            'goodMax' => $this->vital->goodMax(),
            'poorMin' => $this->vital->poorMin(),
            'count' => $this->count,
            'value' => $this->value,
            'avgValue' => $this->averageValue,
            'minValue' => $this->minValue,
            'maxValue' => $this->maxValue,
            'rating' => $this->rating?->value,
            'ratingLabel' => $this->rating?->label(),
            'ratings' => $this->ratings,
            'shares' => $this->shares(),
            'impact' => $this->impact(),
            'trend' => $this->trend->direction->value,
            'trendLabel' => $this->trend->direction->label(),
            'changeRatio' => $this->trend->changeRatio,
        ];
    }

    /**
     * Die drei Zähler einer Ergebniszeile, immer vollständig.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, int>
     */
    private static function ratingsFrom(array $row): array
    {
        $ratings = [];

        foreach (VitalRating::ordered() as $rating) {
            $ratings[$rating->value] = (int) ($row[$rating->column()] ?? 0);
        }

        return $ratings;
    }

    /**
     * @return array<string, int>
     */
    private static function zeroRatings(): array
    {
        return array_fill_keys(
            array_map(static fn (VitalRating $rating): string => $rating->value, VitalRating::ordered()),
            0,
        );
    }

    /**
     * Die Klasse, in die das Perzentil fällt — exakt, aus den Zählern.
     *
     * Aufgerundet wie überall sonst auch, damit dieselbe Stelle gemeint ist wie
     * bei der Verteilung ({@see VitalHistogram::percentile()}): bei vier
     * Messungen ist das p75 die dritte, nicht die zweite.
     *
     * @param  array<string, int>  $ratings
     */
    private static function ratingFrom(array $ratings, int $count): VitalRating
    {
        $target = (int) max(1, ceil(WebVital::PERCENTILE * $count));
        $seen = 0;

        foreach (VitalRating::ordered() as $rating) {
            $seen += $ratings[$rating->value] ?? 0;

            if ($seen >= $target) {
                return $rating;
            }
        }

        // Nicht erreichbar, solange die Zähler zusammen die Anzahl ergeben — und
        // genau dann ist die schlechteste Klasse die richtige Antwort, weil das
        // Perzentil jenseits aller gezählten Messungen läge.
        return VitalRating::Poor;
    }

    /**
     * Rückt den Schätzwert in die Grenzen der Klasse, in der das Perzentil
     * nachweislich liegt.
     *
     * Der Schätzwert stammt aus einer Verteilung mit einem Fehler von ±9 %; die
     * Klasse ist exakt. Fällt er aus ihr heraus, ist er es, der danebenliegt —
     * das Zurechtrücken bringt ihn näher an den wahren Wert, nicht weiter weg.
     * Und es verhindert die schlimmste Anzeige, die diese Seite haben könnte:
     * „2,6 s" mit einem grünen Punkt daneben.
     */
    private static function clamp(WebVital $vital, ?int $value, VitalRating $rating): ?int
    {
        if ($value === null) {
            return null;
        }

        return match ($rating) {
            VitalRating::Good => min($value, $vital->goodMax()),
            VitalRating::NeedsImprovement => min(max($value, $vital->goodMax() + 1), $vital->poorMin()),
            VitalRating::Poor => max($value, $vital->poorMin() + 1),
        };
    }

    /**
     * Der Vergleich mit dem Vorzeitraum.
     *
     * Verglichen wird dasselbe Perzentil, mit derselben Mechanik wie bei den
     * Antwortzeiten ({@see TransactionTrend}) — samt Mindestzahl an Messungen
     * und Band um die Null. Größer heißt auch hier schlechter: für jeden Web
     * Vital gilt „je kleiner, desto besser", auch für den Verschiebungswert.
     *
     * Der Vergleichswert wird **nicht** zurechtgerückt: das Zurechtrücken
     * bezieht sich auf die Bewertung des laufenden Zeitraums, und eine Änderung
     * soll aus zwei gleich gerechneten Zahlen entstehen.
     *
     * @param  array<string, mixed>|null  $previous
     */
    private static function trend(
        WebVital $vital,
        VitalHistogram $histogram,
        int $count,
        ?array $previous,
    ): TransactionTrend {
        if ($previous === null) {
            return TransactionTrend::unknown();
        }

        $before = VitalHistogram::fromRowSums($previous);

        return TransactionTrend::between(
            $histogram->percentile(WebVital::PERCENTILE),
            $count,
            $before->percentile(WebVital::PERCENTILE),
            (int) ($previous['measurement_count'] ?? 0),
        );
    }
}
