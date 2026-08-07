<?php

namespace App\Support\Crons;

use App\Enums\CronCheckInStatus;
use App\Models\CronCheckIn;
use App\Models\CronMonitor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Der Teil der Überwachung, den kein Check-in auslösen kann: die Feststellung,
 * dass **nichts** gekommen ist.
 *
 * Zwei Fälle, die auf denselben Punkt hinauslaufen — ein Job, der nichts von
 * sich hören lässt:
 *
 *   verpasst  — der Termin samt Toleranzfenster ist verstrichen, ohne dass sich
 *               etwas gemeldet hat.
 *   zu lange  — eine Ausführung hat begonnen und ist nach Ablauf der erlaubten
 *               Laufzeit immer noch nicht abgeschlossen.
 *
 * Läuft minütlich (siehe routes/console.php). Die Auflösung der Überwachung ist
 * damit eine Minute — feiner ergibt kein Zeitplan Sinn, gröber würde ein
 * Toleranzfenster von fünf Minuten zu einem von zehn.
 */
final class CronMonitorSweep
{
    public function __construct(private readonly CronAlerts $alerts) {}

    /**
     * Ein Durchlauf. Gibt zurück, was dabei festgestellt wurde — die Konsole
     * schreibt es hin, die Tests prüfen es.
     *
     * @return array{missed: int, timeout: int}
     */
    public function run(?Carbon $now = null): array
    {
        $now ??= now();

        return [
            'missed' => $this->collectMissed($now),
            'timeout' => $this->collectTimeouts($now),
        ];
    }

    /**
     * Ausgebliebene Ausführungen.
     *
     * Der Kern ist der Vergleich `next_due_at + Toleranz < jetzt`. Steht er
     * an, wird ein Verlaufseintrag „verpasst" geschrieben und der Termin auf
     * den **nächsten** Lauf gesetzt — sonst würde derselbe verpasste Termin in
     * der nächsten Minute erneut auffallen und den Verlauf zumüllen.
     *
     * Ein Monitor, dessen Termin lange zurückliegt (die Anwendung stand),
     * erzeugt trotzdem nur **einen** Eintrag statt einen je ausgelassener
     * Stunde: das Ergebnis ist dasselbe — der Job läuft nicht —, und hundert
     * gleichlautende Zeilen sagen nicht mehr als eine.
     */
    private function collectMissed(Carbon $now): int
    {
        $count = 0;

        CronMonitor::query()
            ->due($now)
            ->with('project.organization')
            ->chunkById(100, function ($monitors) use ($now, &$count): void {
                foreach ($monitors as $monitor) {
                    if ($this->markMissed($monitor, $now)) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    private function markMissed(CronMonitor $monitor, Carbon $now): bool
    {
        $deadline = $monitor->dueDeadline();

        // Die Vorauswahl in SQL kennt das Toleranzfenster nicht (es steht in
        // einer Spalte) — die genaue Entscheidung fällt deshalb erst hier.
        if ($deadline === null || $deadline->greaterThan($now)) {
            return false;
        }

        // Eine laufende Ausführung ist kein verpasster Termin: der Job hat sich
        // gemeldet, er ist nur noch nicht fertig. Ob er zu lange braucht,
        // entscheidet die Laufzeitprüfung.
        if ($monitor->openCheckIns()->exists()) {
            $monitor->scheduleNextDue($now);
            $monitor->save();

            return false;
        }

        $expected = $monitor->next_due_at;

        $checkIn = DB::transaction(function () use ($monitor, $now, $expected): CronCheckIn {
            $checkIn = CronCheckIn::record(
                monitor: $monitor,
                status: CronCheckInStatus::Missed,
                at: $now,
                expectedAt: $expected,
            );

            $monitor->applyCheckIn(CronCheckInStatus::Missed, $now);
            $monitor->scheduleNextDue($now);
            $monitor->save();

            return $checkIn;
        });

        $this->alertIfNeeded($monitor, $checkIn);

        return true;
    }

    /**
     * Hängende Ausführungen.
     *
     * Gesucht wird über alle Monitore hinweg statt Monitor für Monitor: offene
     * Ausführungen sind selten, und die Alternative wäre eine Abfrage je
     * Monitor. Die erlaubte Laufzeit steht allerdings am Monitor, deshalb
     * dieselbe Aufteilung wie oben — grobe Vorauswahl in SQL, Entscheidung in
     * PHP.
     */
    private function collectTimeouts(Carbon $now): int
    {
        $count = 0;

        CronCheckIn::query()
            ->where('status', CronCheckInStatus::InProgress)
            ->whereNotNull('started_at')
            ->with('monitor.project.organization')
            ->chunkById(100, function ($checkIns) use ($now, &$count): void {
                foreach ($checkIns as $checkIn) {
                    if ($this->markTimeout($checkIn, $now)) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    private function markTimeout(CronCheckIn $checkIn, Carbon $now): bool
    {
        $monitor = $checkIn->monitor;

        if (! $monitor->is_active || $checkIn->started_at === null) {
            return false;
        }

        if ($checkIn->started_at->copy()->addMinutes($monitor->max_runtime_minutes)->greaterThan($now)) {
            return false;
        }

        DB::transaction(function () use ($monitor, $checkIn, $now): void {
            $checkIn->finish(CronCheckInStatus::Timeout, null, $now);

            $monitor->applyCheckIn(CronCheckInStatus::Timeout, $now);

            // Der Termin wird erst jetzt fortgeschrieben: bis hierher stand der
            // Lauf noch aus, und sein geplanter Zeitpunkt gehörte zu ihm.
            $monitor->scheduleNextDue($now);
            $monitor->save();
        });

        $this->alertIfNeeded($monitor, $checkIn);

        return true;
    }

    /**
     * Dieselbe Entscheidung wie bei einem gemeldeten Fehlschlag: erst wenn die
     * Fehlertoleranz aufgebraucht ist, und nur einmal je Störung.
     */
    private function alertIfNeeded(CronMonitor $monitor, CronCheckIn $checkIn): void
    {
        if (! $monitor->needsAlert()) {
            return;
        }

        $this->alerts->fired($monitor, $checkIn);

        $monitor->alerted_at = now();
        $monitor->save();
    }
}
