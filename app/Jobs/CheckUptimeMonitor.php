<?php

namespace App\Jobs;

use App\Enums\QueueName;
use App\Models\UptimeMonitor;
use App\Support\Uptime\UptimeProbe;
use App\Support\Uptime\UptimeRecorder;
use App\Support\Uptime\UptimeSweep;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Prüft ein Ziel — der einzige Ort, an dem eine Erreichbarkeits-Prüfung
 * stattfindet.
 *
 * **Das ist die Zusage der Aufgabe: nie im Web-Request.** Sie ist keine
 * Ordnungsfrage. Eine Prüfung wartet bis zur Zeitgrenze auf eine Gegenstelle,
 * die nicht antwortet, und tut das wegen der Bestätigung gegebenenfalls
 * zweimal. Im Web-Request hieße das: die Seite, auf der jemand nachsieht, ob
 * etwas ausgefallen ist, hängt selbst — und zwar genau dann, wenn etwas
 * ausgefallen ist.
 *
 * Eingereiht wird je fälligem Monitor einer ({@see UptimeSweep}).
 * Der Monitor kommt als **Nummer** herein und nicht als Modell: zwischen dem
 * Einreihen und dem Ausführen liegt eine Warteschlange, und was dort steht,
 * kann inzwischen abgeschaltet oder gelöscht worden sein. Ein mitgeschicktes
 * Modell wäre eine Momentaufnahme von vorhin — geprüft werden soll aber die
 * Einstellung von jetzt.
 */
class CheckUptimeMonitor implements ShouldQueue
{
    use Queueable;

    /**
     * Ein Versuch, nicht drei.
     *
     * Die Wiederholung gibt es schon — sie steckt als Bestätigung in der
     * Prüfung selbst und ist dort einstellbar. Eine zweite Wiederholung durch
     * die Warteschlange käme Minuten später und würde eine Messung von jetzt
     * mit einem Zeitstempel von vorhin schreiben. Und was hier scheitert, ist
     * ohnehin selten die Gegenstelle: ein nicht erreichbares Ziel ist für
     * diesen Auftrag kein Fehler, sondern das Ergebnis.
     */
    public int $tries = 1;

    /**
     * Großzügig gegenüber der Summe aus Zeitgrenze, Bestätigung und Abstand:
     * die Zeitgrenze der Anfrage ist die eigentliche Bremse, und diese hier
     * soll nur einen hängenden Arbeiter einfangen.
     */
    public int $timeout = 180;

    public function __construct(public int $monitorId)
    {
        $this->onQueue(QueueName::Uptime->value);
    }

    /**
     * Ein Ziel wird nicht zweimal gleichzeitig geprüft.
     *
     * Ohne die Sperre könnten sich Prüfungen überholen, wenn eine länger
     * braucht als der Takt — und zwei gleichzeitige Messungen desselben Ziels
     * ergäben zwei Verlaufszeilen mit derselben Aussage, aber möglicherweise
     * gegenläufigem Ausgang. `dontRelease`, weil eine verspätet nachgeholte
     * Prüfung wertlos ist: die nächste steht ohnehin gleich an.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('uptime-check:'.$this->monitorId))
                ->dontRelease()
                ->expireAfter($this->timeout + 60),
        ];
    }

    public function handle(UptimeProbe $probe, UptimeRecorder $recorder): void
    {
        $monitor = UptimeMonitor::query()->with('project.organization')->find($this->monitorId);

        // Zwischen Einreihen und Ausführen gelöscht oder abgeschaltet. Beides
        // ist kein Fehler — es ist der Normalfall, wenn jemand während einer
        // Störung die Überwachung stilllegt.
        if ($monitor === null || ! $monitor->is_active) {
            return;
        }

        $recorder->record($monitor, $probe->run($monitor));
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('Erreichbarkeits-Prüfung gescheitert.', [
            'monitor' => $this->monitorId,
            'grund' => $exception?->getMessage(),
        ]);
    }
}
