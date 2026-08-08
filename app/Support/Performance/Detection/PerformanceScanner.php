<?php

namespace App\Support\Performance\Detection;

use App\Models\Transaction;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;

/**
 * Der Ablauf der Erkennung: einen gespeicherten Ablauf einmal ansehen und alles
 * melden, was auffällt.
 *
 * **Die Reihenfolge der Erkenner ist eine fachliche Entscheidung**, keine
 * Formsache. Mehrere Muster passen auf dieselben Schritte — fünf identische
 * Abfragen hintereinander sind doppelt, gleichartig **und** sähen mit einer
 * Abfrage davor wie ein N+1 aus. Alle drei zu melden hieße, dieselbe Baustelle
 * dreimal in die Liste zu schreiben; wer sie abarbeitet, behebt einmal und
 * schließt dreimal.
 *
 * Deshalb gilt: **wer zuerst kommt, behält die Schritte.** Ein Fund, dessen
 * Schritte schon vergeben sind, entfällt. Die Reihenfolge steht in
 * `config/ingest.php` und ist von der genaueren Aussage zur allgemeineren
 * geordnet — „exakt dieselbe Abfrage" vor „gleichartige Abfrage in einer
 * Schleife" vor „gleichartige Abfragen nacheinander". Die genauere Aussage ist
 * die nützlichere: sie sagt, was zu tun ist.
 */
final class PerformanceScanner
{
    /**
     * @param  list<Detector>  $detectors
     */
    public function __construct(
        private readonly array $detectors,
        private readonly PerformanceIssues $issues,
    ) {}

    /**
     * Die Erkenner aus der Konfiguration, in der dort festgelegten Reihenfolge.
     */
    public static function fromConfig(?PerformanceIssues $issues = null): self
    {
        $configured = config('ingest.performance.detectors');

        if (! is_array($configured)) {
            throw new InvalidArgumentException('ingest.performance.detectors muss eine Liste von Klassennamen sein.');
        }

        $detectors = [];

        foreach ($configured as $class) {
            if (! is_string($class) || ! class_exists($class)) {
                throw new InvalidArgumentException(
                    'Unbekannter Erkenner in ingest.performance.detectors: '
                    .(is_string($class) ? $class : get_debug_type($class))
                );
            }

            $detector = app($class);

            if (! $detector instanceof Detector) {
                throw new InvalidArgumentException($class.' ist kein '.Detector::class.'.');
            }

            $detectors[] = $detector;
        }

        return new self($detectors, $issues ?? new PerformanceIssues);
    }

    /**
     * Sieht sich einen Ablauf an und legt an, was gefunden wurde.
     *
     * @return int Wie viele Funde neu waren.
     */
    public function scan(Transaction $transaction): int
    {
        $project = $transaction->project;

        if ($project === null) {
            return 0;
        }

        $thresholds = Thresholds::forProject($project);
        $trace = TraceSnapshot::of($transaction);

        $recorded = 0;

        /** @var array<string, true> $claimed */
        $claimed = [];

        foreach ($this->detectors as $detector) {
            if (! $thresholds->isEnabled($detector->problem())) {
                continue;
            }

            foreach ($detector->detect($trace, $thresholds) as $finding) {
                if ($finding->spanIds === [] || $this->isClaimed($finding, $claimed)) {
                    continue;
                }

                foreach ($finding->spanIds as $spanId) {
                    $claimed[$spanId] = true;
                }

                if ($this->issues->record($transaction, $finding) !== null) {
                    $recorded++;
                }
            }
        }

        // Erst am Ende. Vorher gesetzt wäre der Vermerk ein Versprechen, das
        // ein abgebrochener Lauf nicht hält: der erneute Anlauf sähe „schon
        // angesehen" und ließe die halbe Auswertung liegen. Dass ein Ablauf
        // dabei zweimal untersucht werden kann, ist folgenlos — der eindeutige
        // Index am Fund lässt denselben Vorfall kein zweites Mal entstehen.
        Transaction::query()
            ->whereKey($transaction->id)
            ->update(['scanned_at' => Date::now()]);

        return $recorded;
    }

    /**
     * Sind alle Schritte dieses Fundes schon vergeben?
     *
     * **Alle**, nicht einige: ein einzelner überschneidender Schritt macht zwei
     * Funde nicht zu einem. Eine langsame Abfrage, die zugleich Teil einer
     * Serie ist, bleibt eine eigene Aussage — erst wenn ein Fund vollständig in
     * einem früheren aufgeht, beschreibt er dieselbe Baustelle.
     *
     * @param  array<string, true>  $claimed
     */
    private function isClaimed(Finding $finding, array $claimed): bool
    {
        foreach ($finding->spanIds as $spanId) {
            if (! isset($claimed[$spanId])) {
                return false;
            }
        }

        return true;
    }
}
