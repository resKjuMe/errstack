<?php

namespace App\Support\Uptime;

use App\Jobs\CheckUptimeMonitor;
use App\Models\UptimeMonitor;
use Illuminate\Support\Carbon;

/**
 * Der minütliche Blick auf die Uhr: welche Ziele sind jetzt an der Reihe?
 *
 * Der Sweep **prüft nicht selbst**. Er sucht die fälligen Monitore und reiht je
 * einen Job ein; geprüft wird in der Warteschlange. Das ist kein Feinschliff,
 * sondern die Bedingung der Aufgabe — und sie hat einen handfesten Grund: eine
 * Prüfung wartet bis zur Zeitgrenze auf eine Gegenstelle, die nicht antwortet.
 * Zwanzig ausgefallene Ziele in einem Durchlauf wären zwanzigmal diese
 * Wartezeit hintereinander, und der Durchlauf käme nie zum Ende — genau dann,
 * wenn die Überwachung gebraucht wird.
 *
 * **Die Fälligkeit wird sofort fortgeschrieben, nicht erst nach der Prüfung.**
 * Sonst griffe der nächste Durchlauf eine Minute später dieselben Monitore
 * erneut ab, solange die Jobs noch laufen, und ein langsames Ziel bekäme in
 * zehn Minuten zehn gleichzeitige Prüfungen.
 */
final class UptimeSweep
{
    /**
     * Ein Durchlauf. Gibt zurück, wie viele Prüfungen eingereiht wurden — die
     * Konsole schreibt es hin, die Tests prüfen es.
     */
    public function run(?Carbon $now = null): int
    {
        $now ??= Carbon::now();
        $queued = 0;

        UptimeMonitor::query()
            ->due($now)
            ->chunkById(100, function ($monitors) use ($now, &$queued): void {
                foreach ($monitors as $monitor) {
                    $this->dispatch($monitor, $now);
                    $queued++;
                }
            });

        return $queued;
    }

    private function dispatch(UptimeMonitor $monitor, Carbon $now): void
    {
        // Erst reservieren, dann einreihen. Andersherum könnte der Job schon
        // gelaufen und die Fälligkeit fortgeschrieben haben, bevor diese Zeile
        // sie überschreibt — und der Monitor wäre nach seiner eigenen Prüfung
        // wieder fällig.
        $monitor->scheduleNextCheck($now);
        $monitor->save();

        CheckUptimeMonitor::dispatch($monitor->id);
    }
}
