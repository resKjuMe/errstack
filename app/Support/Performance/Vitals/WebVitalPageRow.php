<?php

namespace App\Support\Performance\Vitals;

use App\Enums\VitalRating;
use App\Enums\WebVital;

/**
 * Eine Seite in der Web-Vitals-Übersicht: ihr Name und je Messwert eine
 * Zusammenfassung.
 *
 * Eine Zeile trägt **immer alle** Messwerte, auch die, für die nichts gemeldet
 * wurde ({@see VitalSummary::empty()}). Das ist Absicht: eine Tabelle, in der
 * die Spalte „INP" bei der einen Seite fehlt und bei der anderen dasteht, lässt
 * sich nicht lesen — und „keine Daten" ist die Auskunft, um derer willen jemand
 * nachsieht. Wer INP nirgends misst, soll das sehen und nicht raten, ob die
 * Spalte leer oder die Seite gut ist.
 */
final class WebVitalPageRow
{
    /**
     * @param  array<string, VitalSummary>  $vitals  Messwert-Schlüssel →
     *                                               Zusammenfassung, in der
     *                                               Reihenfolge von
     *                                               {@see WebVital::cases()}.
     */
    private function __construct(
        public readonly string $name,
        public readonly array $vitals,
    ) {}

    /**
     * @param  array<string, VitalSummary>  $vitals  Was gemessen wurde; was
     *                                               fehlt, wird ergänzt.
     */
    public static function make(string $name, array $vitals): self
    {
        $complete = [];

        foreach (WebVital::cases() as $vital) {
            $complete[$vital->value] = $vitals[$vital->value] ?? VitalSummary::empty($vital);
        }

        return new self($name, $complete);
    }

    /**
     * Eine Seite, von der Aufrufe bekannt sind, aber kein einziger Messwert.
     */
    public static function withoutData(string $name): self
    {
        return self::make($name, []);
    }

    /**
     * Wurde für diese Seite überhaupt etwas gemessen?
     */
    public function hasData(): bool
    {
        return $this->measurements() > 0;
    }

    /**
     * Die größte Zahl an Messungen unter den Messwerten dieser Seite.
     *
     * Nicht die Summe: dieselbe Seitenansicht liefert bis zu sechs Werte, und
     * eine Summe darüber wäre eine sechsfach überhöhte Zahl von Ladevorgängen.
     * Das Größte ist die belastbare Untergrenze — so viele Ladevorgänge haben
     * mindestens gemeldet.
     */
    public function measurements(): int
    {
        $measurements = 0;

        foreach ($this->vitals as $summary) {
            $measurements = max($measurements, $summary->count);
        }

        return $measurements;
    }

    /**
     * Wie viele Ladevorgänge über alle **Kernwerte** hinweg nicht in Ordnung
     * waren, schlechte doppelt gezählt.
     *
     * Die Rangfolge der Übersicht. Nur die Kernwerte, weil die übrigen dieselbe
     * Ursache ein zweites Mal zählten: ein langsames TTFB macht das LCP langsam,
     * und eine Seite stünde allein deshalb doppelt so weit oben. FCP und TTFB
     * erklären den Befund, sie sind nicht selbst einer.
     */
    public function impact(): int
    {
        $impact = 0;

        foreach (WebVital::core() as $vital) {
            $impact += $this->vitals[$vital->value]->impact();
        }

        return $impact;
    }

    /**
     * Die schlechteste Bewertung unter den Kernwerten, oder `null`, wenn keiner
     * von ihnen gemessen wurde.
     *
     * Sie ist die Bewertung der **Seite**: eine Seite, die schnell erscheint,
     * aber unter der Hand wegspringt, ist keine gute Seite. Die Spezifikation
     * hält es ebenso — bestanden hat, wer in allen Kernwerten gut ist.
     */
    public function worstRating(): ?VitalRating
    {
        $worst = null;

        foreach (WebVital::core() as $vital) {
            $rating = $this->vitals[$vital->value]->rating;

            if ($rating !== null && ($worst === null || $rating->worseThan($worst))) {
                $worst = $rating;
            }
        }

        return $worst;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $worst = $this->worstRating();

        return [
            'name' => $this->name,
            'hasData' => $this->hasData(),
            'measurements' => $this->measurements(),
            'impact' => $this->impact(),
            'rating' => $worst?->value,
            'ratingLabel' => $worst?->label(),
            'vitals' => array_map(
                static fn (VitalSummary $summary): array => $summary->toArray(),
                $this->vitals,
            ),
        ];
    }

    /**
     * Sortiert die Zeilen: das größte Problem zuerst.
     *
     * Bei Gleichstand nach Namen, und zwar immer aufsteigend — ohne dieses
     * zweite Merkmal stünden alle Seiten ohne Messwerte (Wirkung 0) in der
     * Reihenfolge, in der die Datenbank sie zufällig geliefert hat, und die
     * Seite sähe bei jedem Aufruf anders aus.
     *
     * @param  list<self>  $rows
     * @return list<self>
     */
    public static function sorted(array $rows): array
    {
        usort($rows, static fn (self $a, self $b): int => [$b->impact(), $a->name] <=> [$a->impact(), $b->name]);

        return $rows;
    }
}
