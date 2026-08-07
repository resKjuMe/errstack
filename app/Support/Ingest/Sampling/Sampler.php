<?php

namespace App\Support\Ingest\Sampling;

use App\Models\Project;
use App\Models\SamplingRule;

/**
 * Entscheidet, ob eine gemeldete Transaktion behalten wird.
 *
 * Drei Fragen in dieser Reihenfolge, und die Reihenfolge ist die eigentliche
 * Aussage der Klasse:
 *
 *   1. **Welche Regel gilt?** Die erste zutreffende gewinnt. Trifft keine zu,
 *      gilt die Vorgabe aus der Konfiguration — sie steht auf „alles behalten",
 *      damit eine Stichprobe eine Entscheidung ist und keine Voreinstellung.
 *   2. **Ist der Vorgang selten?** Die ersten `n` Meldungen je Fenster werden
 *      behalten, ganz gleich wie niedrig die Quote ist. Ohne diese Frage
 *      verschwindet bei 1 % ausgerechnet der nächtliche Import, der einmal je
 *      Stunde läuft.
 *   3. **Fällt der Wurf in die Quote?** Erst hier entscheidet der Zufall.
 *
 * Die Klasse arbeitet **ohne** Kenntnis der Meldung: sie bekommt vier
 * Zeichenketten ({@see SampleTarget}) und gibt eine Entscheidung zurück. Damit
 * ist sie ohne Warteschlange, Job und Datenaufnahme prüfbar — und das ist bei
 * einer Entscheidung, die zu Recht nicht wiederholbar ist, der einzige Weg, ihr
 * zu trauen.
 */
final class Sampler
{
    /**
     * Kleinste Quote, mit der noch gerechnet wird.
     *
     * Sie entspricht der Genauigkeit der Spalte (acht Nachkommastellen). Der
     * Grund für eine Untergrenze ist nicht die Sparsamkeit, sondern die
     * Umkehrung: aus der Quote wird ein Gewicht, und eine Quote von 0 hätte kein
     * Gewicht, sondern eine Division.
     */
    public const MIN_RATE = 0.00000001;

    /**
     * Wie fein der Wurf ist.
     *
     * Eine Million Stufen: die Quote hat acht Nachkommastellen, der Wurf sechs.
     * Feiner würde nichts gewinnen — bei einer Million Aufrufen ist der
     * Unterschied zwischen 0,000001 und 0,0000015 ein einzelner Datensatz —, und
     * ganze Zahlen sind der einzige Weg, einen Zufallswert ohne
     * Gleitkomma-Überraschungen zu ziehen.
     */
    public const DRAW_RESOLUTION = 1_000_000;

    /**
     * Der Wurf: eine Zahl von 0 (einschließlich) bis 1 (ausschließlich).
     *
     * @var callable(): float
     */
    private $draw;

    /**
     * @param  (callable(): float)|null  $draw  Der Wurf. Ersetzbar, weil eine
     *                                          Entscheidung mit Zufall sonst
     *                                          nicht prüfbar wäre — und eine
     *                                          Stichprobe, die man nur statistisch
     *                                          prüfen kann, prüft niemand.
     */
    public function __construct(
        private readonly WindowCounter $counter,
        ?callable $draw = null,
    ) {
        $this->draw = $draw ?? static fn (): float => random_int(0, self::DRAW_RESOLUTION - 1) / self::DRAW_RESOLUTION;
    }

    public function decide(Project $project, SampleTarget $target): SamplingDecision
    {
        $rule = $this->matching($project, $target);
        $rate = $this->rate($rule);

        if ($rate >= 1.0) {
            // Alles behalten. Der Zähler bleibt in diesem Fall unangetastet: ein
            // Projekt ohne Stichprobe soll den Zwischenspeicher nicht mit einem
            // Eintrag je Vorgang und Minute füllen.
            return SamplingDecision::keep($target->clientSampleRate, 1.0, $rule);
        }

        $seen = $this->counter->reserve($project, $target);
        $minimum = $rule?->minimum_per_window ?? $this->defaultMinimum();

        if ($seen <= $minimum) {
            return SamplingDecision::guaranteed($target->clientSampleRate, $rule);
        }

        if (($this->draw)() < $rate) {
            return SamplingDecision::keep($target->clientSampleRate, $rate, $rule);
        }

        return SamplingDecision::discard($target->clientSampleRate, $rate, $rule);
    }

    /**
     * Die erste zutreffende Regel des Projekts.
     *
     * Geprüft wird in PHP und nicht in der Datenbank. Das ist keine Bequemlichkeit:
     * die Bedingungen sind Platzhalter-Muster, und ein `LIKE` je Spalte und Regel
     * wäre eine Abfrage, die keinen Index benutzen kann. Die Regeln eines
     * Projekts sind auf {@see SamplingRule::MAX_PER_PROJECT} begrenzt — sie
     * einmal zu holen und der Reihe nach zu prüfen ist billiger als jeder
     * Versuch, das der Datenbank zu überlassen.
     */
    private function matching(Project $project, SampleTarget $target): ?SamplingRule
    {
        foreach ($project->samplingRules()->inOrder()->get() as $rule) {
            if ($rule->matches($target)) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * Die Quote einer Regel, auf den brauchbaren Bereich gebracht.
     */
    private function rate(?SamplingRule $rule): float
    {
        $rate = $rule?->sample_rate ?? (float) config('ingest.sampling.default_rate', 1.0);

        if (! is_finite($rate) || $rate >= 1.0) {
            return 1.0;
        }

        return max(self::MIN_RATE, $rate);
    }

    /**
     * Die Mindestquote für Aufrufe, auf die keine Regel zutrifft.
     *
     * Sie greift nur, wenn die Vorgabe unter 1 liegt — ohne Stichprobe gibt es
     * keine Untergrenze zu sichern.
     */
    private function defaultMinimum(): int
    {
        return max(0, (int) config('ingest.sampling.minimum_per_window', 1));
    }
}
