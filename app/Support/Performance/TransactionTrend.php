<?php

namespace App\Support\Performance;

use App\Enums\TrendDirection;

/**
 * Der Vergleich einer Transaktion mit sich selbst im vorangegangenen Zeitraum.
 *
 * Verglichen wird das **p95** und nicht der Mittelwert: der Mittelwert einer
 * Antwortzeit-Verteilung wandert kaum, weil ihn die Masse der schnellen Aufrufe
 * festhält — genau die Verschlechterung, die Nutzer merken, steckt im oberen
 * Rand. Und nicht der Durchsatz: dass eine Seite häufiger aufgerufen wird, ist
 * keine Verschlechterung.
 *
 * Zwei Vorsichtsmaßnahmen halten den Pfeil davon ab, Rauschen anzuzeigen:
 *
 *   - Ein Band um die Null ({@see FLAT_BAND}), innerhalb dessen eine Änderung
 *     als „unverändert" gilt. Ohne es zeigte jede Transaktion einen Pfeil, denn
 *     zwei Zeiträume sind nie exakt gleich.
 *   - Eine Mindestzahl an Messungen auf **beiden** Seiten
 *     ({@see MINIMUM_SAMPLES}). Ein p95 aus zwei Messungen ist die langsamere
 *     der beiden; ein einzelner Ausreißer würde daraus eine Verschlechterung um
 *     das Zehnfache machen.
 *
 * Die Klassenbreite der Verteilung ({@see DurationHistogram}) setzt hier eine
 * Grenze, die man kennen muss: das p95 springt in Verdopplungsschritten. Eine
 * ausgewiesene Änderung ist deshalb immer ein Sprung über eine Klassengrenze,
 * und eine echte Verschlechterung innerhalb einer Klasse bleibt unsichtbar. Für
 * „ist das langsamer geworden" reicht das; für die Feinmessung steht die
 * Detailseite bereit (PF3).
 */
final class TransactionTrend
{
    /**
     * Relative Änderung, bis zu der nichts gemeldet wird — fünf Prozent.
     */
    public const FLAT_BAND = 0.05;

    /**
     * Messungen, die je Zeitraum vorliegen müssen, damit überhaupt verglichen
     * wird.
     */
    public const MINIMUM_SAMPLES = 5;

    /**
     * @param  float|null  $changeRatio  Die relative Änderung des p95: 0,2 heißt
     *                                   „20 % langsamer", -0,2 „20 % schneller".
     *                                   `null`, wenn es nichts zu vergleichen
     *                                   gab.
     */
    private function __construct(
        public readonly TrendDirection $direction,
        public readonly ?float $changeRatio,
    ) {}

    /**
     * Kein Vergleich möglich, ohne dass etwas fehlt.
     *
     * Nicht dasselbe wie `between(null, 0, null, 0)`: das hieße „im Vorzeitraum
     * nicht gemessen" und damit „neu". Wo es in **beiden** Zeiträumen nichts
     * gibt, ist nichts neu — die Auskunft ist, dass sich nichts vergleichen
     * lässt.
     */
    public static function unknown(): self
    {
        return new self(TrendDirection::Unknown, null);
    }

    /**
     * @param  int|null  $currentUs  p95 des gewählten Zeitraums
     * @param  int  $currentSamples  Messungen darin (nicht hochgerechnet — die
     *                               Hochrechnung sagt nichts darüber, wie
     *                               belastbar die Verteilung ist)
     * @param  int|null  $previousUs  p95 des Vorzeitraums
     */
    public static function between(?int $currentUs, int $currentSamples, ?int $previousUs, int $previousSamples): self
    {
        // Vorher gar nichts: die Transaktion ist neu. Das ist eine Auskunft für
        // sich — ein neuer Endpunkt, der langsam ist, ist eine andere Nachricht
        // als einer, der langsam geworden ist.
        if ($previousUs === null || $previousSamples === 0) {
            return new self(TrendDirection::New, null);
        }

        if ($currentUs === null || $currentSamples === 0) {
            return new self(TrendDirection::Unknown, null);
        }

        if ($currentSamples < self::MINIMUM_SAMPLES || $previousSamples < self::MINIMUM_SAMPLES) {
            return new self(TrendDirection::Unknown, null);
        }

        // Kann nicht vorkommen, solange das p95 aus einer Verteilung stammt —
        // aber eine Division durch Null ist ein zu hoher Preis für diese
        // Gewissheit.
        if ($previousUs <= 0) {
            return new self(TrendDirection::Unknown, null);
        }

        $change = $currentUs / $previousUs - 1.0;

        if (abs($change) < self::FLAT_BAND) {
            return new self(TrendDirection::Flat, $change);
        }

        // Größer heißt langsamer heißt schlechter. Bei Antwortzeiten ist die
        // Richtung eindeutig, anders als beim Durchsatz.
        return new self($change > 0 ? TrendDirection::Worse : TrendDirection::Better, $change);
    }
}
