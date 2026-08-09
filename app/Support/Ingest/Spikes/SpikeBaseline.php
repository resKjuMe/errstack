<?php

namespace App\Support\Ingest\Spikes;

use App\Models\IngestVolume;
use App\Models\Project;

/**
 * Der Vergleichswert, an dem sich eine Spitze als Spitze zeigt — und die
 * Schwelle, ab der gedrosselt wird.
 *
 * Die Rechnung steht bewusst allein und ohne Datenbank in der Signatur: sie
 * bekommt die gemessenen Minuten als Zahlen und gibt eine Schwelle zurück. Eine
 * Entscheidung, die im Ernstfall Daten wegwirft, muss ohne Warteschlange,
 * Aufnahme und Uhr prüfbar sein — sonst traut ihr niemand, und beim ersten
 * Fehlalarm wird der ganze Schutz abgeschaltet.
 *
 * **Median statt Mittelwert.** Der Mittelwert wäre von genau dem Ausschlag
 * verdorben, den wir suchen: eine einzige Minute mit einer Million Meldungen
 * hebt den Durchschnitt einer Stunde so weit an, dass die nächste Flut als
 * normal durchgeht. Der Median rührt sich davon nicht.
 *
 * **Gedrosselte Minuten zählen nicht mit.** Was während einer Drosselung
 * gemeldet wurde, ist die Spitze selbst; sie in den Vergleichswert
 * aufzunehmen hieße, den Schutz mit jeder Minute seines Wirkens ein Stück weit
 * abzuschalten.
 */
final class SpikeBaseline
{
    /**
     * Wie weit zurück gesehen wird.
     *
     * Drei Stunden: weit genug, dass ein einzelner Ausschlag den Median nicht
     * verschiebt, und kurz genug, dass der Tagesverlauf einer Anwendung noch
     * darin steht. Ein ganzer Tag klänge gründlicher und wäre es nicht — der
     * Vergleichswert einer Mittagsspitze wäre dann zur Hälfte die Nacht.
     */
    public const HISTORY_MINUTES = 180;

    /**
     * Wie viele gemessene Minuten mindestens vorliegen müssen.
     *
     * Ohne Untergrenze wäre die erste Viertelstunde eines frisch
     * eingeschalteten Schutzes die gefährlichste: ein Median aus zwei Minuten
     * ist keine Aussage über den Normalbetrieb, und die dritte Minute stünde
     * schon in der Drosselung. Solange weniger vorliegt, wird **nicht**
     * gedrosselt — der Schutz schweigt lieber, als zu raten.
     */
    public const MINIMUM_SAMPLES = 15;

    /**
     * Der Vergleichswert und die Schwelle eines Projekts, aus seinem Verlauf.
     */
    public static function for(Project $project): self
    {
        /** @var list<int> $history */
        $history = IngestVolume::query()
            ->recent($project, self::HISTORY_MINUTES)
            ->where('throttled', false)
            ->pluck('quantity')
            ->map(static fn (mixed $quantity): int => (int) $quantity)
            ->all();

        return self::fromHistory(
            $history,
            (float) $project->spike_threshold_factor,
            (int) $project->spike_minimum_events,
        );
    }

    /**
     * @param  list<int>  $history  Gemessene Minuten, Reihenfolge gleichgültig.
     * @param  float  $factor  Ab dem Wievielfachen des Vergleichswerts eine Minute als Spitze gilt.
     * @param  int  $minimum  Untergrenze, unterhalb derer nie gedrosselt wird.
     */
    public static function fromHistory(array $history, float $factor, int $minimum): self
    {
        return new self(
            samples: count($history),
            baseline: self::median($history),
            factor: max(1.0, $factor),
            minimum: max(0, $minimum),
        );
    }

    private function __construct(
        public readonly int $samples,
        public readonly float $baseline,
        public readonly float $factor,
        public readonly int $minimum,
    ) {}

    /**
     * Die Menge je Minute, ab der gedrosselt wird — `0` heißt „gar nicht".
     *
     * Zwei Bedingungen, und beide müssen erfüllt sein: das Vielfache des
     * Vergleichswerts **und** die Untergrenze. Die Untergrenze ist der Schutz
     * des Schutzes — bei einem Vergleichswert von zwei Ereignissen je Minute
     * wäre das Fünffache zehn, und ein ruhiges Projekt stünde beim ersten
     * kurzen Ausschlag in der Drosselung.
     */
    public function threshold(): int
    {
        if (! $this->isReady()) {
            return 0;
        }

        return max((int) ceil($this->baseline * $this->factor), $this->minimum);
    }

    /**
     * Liegt genug Verlauf vor, um überhaupt zu entscheiden?
     */
    public function isReady(): bool
    {
        return $this->samples >= self::MINIMUM_SAMPLES;
    }

    /**
     * Gilt die Menge dieser Minute wieder als normal?
     *
     * Bewusst nicht dieselbe Schwelle wie beim Auslösen, sondern die Hälfte:
     * eine Menge, die genau um die Schwelle pendelt, würde sonst im
     * Minutentakt drosseln und entdrosseln — und jeder Wechsel ist eine
     * Benachrichtigung.
     */
    public function hasCalmedDown(int $quantity): bool
    {
        $threshold = $this->threshold();

        return $threshold === 0 || $quantity <= (int) floor($threshold / 2);
    }

    /**
     * @param  list<int>  $values
     */
    private static function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values);

        $count = count($values);
        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return (float) $values[$middle];
        }

        return ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
