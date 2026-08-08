<?php

namespace App\Support\Alerts;

use App\Models\MetricAlert;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ein Durchlauf über alle aktiven Alarme — der Teil, den der Zeitplan aufruft.
 *
 * Er ist das Gegenstück zur Cronjob-Überwachung ({@see
 * \App\Support\Crons\CronMonitorSweep}) und hat denselben Grund: eine
 * Verschlechterung meldet sich nicht von selbst. Etwas muss regelmäßig
 * nachsehen, sonst fällt eine steigende Fehlerrate erst auf, wenn jemand die
 * Seite öffnet.
 *
 * **Ein Alarm, der stolpert, hält die anderen nicht auf.** Eine kaputte Regel —
 * ein Vorgangsname, den es nicht mehr gibt, eine Datenbank, die gerade
 * hustet — darf nicht dazu führen, dass zwanzig weitere Alarme in dieser Minute
 * ungeprüft bleiben. Der Fehler wird protokolliert und der Durchlauf geht
 * weiter; die nächste Minute versucht es erneut.
 *
 * **Der Zeitplan läuft `withoutOverlapping`** (siehe `routes/console.php`).
 * Nötig ist das nicht für die Richtigkeit — der Zustandswechsel ist eine
 * bedingte Anweisung und damit gegen doppelte Läufe abgesichert —, sondern
 * gegen das Auflaufen: ein Durchlauf, der länger als eine Minute braucht, würde
 * sonst vom nächsten überholt.
 */
final class MetricAlertSweep
{
    public function __construct(private readonly MetricAlertEvaluator $evaluator) {}

    /**
     * Ein Durchlauf. Gibt zurück, was dabei herauskam — die Konsole schreibt es
     * hin, die Tests prüfen es.
     *
     * @return array{evaluated: int, transitions: int, failed: int}
     */
    public function run(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        $evaluated = 0;
        $transitions = 0;
        $failed = 0;

        // Das Projekt und seine Organisation gleich mit: die Meldung braucht
        // beide, und ohne wäre es je Alarm eine zusätzliche Abfrage — in einem
        // Durchlauf, der jede Minute stattfindet.
        MetricAlert::query()
            ->due()
            ->with('project.organization')
            ->each(function (MetricAlert $alert) use ($now, &$evaluated, &$transitions, &$failed): void {
                try {
                    $evaluated++;

                    if ($this->evaluator->evaluate($alert, $now) !== null) {
                        $transitions++;
                    }
                } catch (Throwable $e) {
                    $failed++;

                    Log::error('Schwellwert-Alarm konnte nicht ausgewertet werden.', [
                        'alert_id' => $alert->id,
                        'project_id' => $alert->project_id,
                        'exception' => $e,
                    ]);
                }
            });

        return ['evaluated' => $evaluated, 'transitions' => $transitions, 'failed' => $failed];
    }
}
