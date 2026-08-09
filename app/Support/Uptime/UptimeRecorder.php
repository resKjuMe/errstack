<?php

namespace App\Support\Uptime;

use App\Models\UptimeCheck;
use App\Models\UptimeMonitor;
use App\Models\UptimeOutage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Was mit dem Ergebnis einer Prüfung geschieht: Verlauf, Zustand, Vorfall,
 * Meldung, Eintrag.
 *
 * Die Stelle, an der aus einer Messung ein Sachverhalt wird. Sie ist bewusst
 * die **einzige**, die den Zustand eines Monitors fortschreibt — Vorfall
 * eröffnen, Zähler bewegen, Vorfall schließen. Läge das an mehreren Stellen,
 * wäre die Frage „gibt es gerade einen offenen Ausfall?" von der Reihenfolge
 * abhängig, in der zwei Arbeiter zufällig ankommen.
 *
 * **Die Reihenfolge innerhalb ist nicht beliebig.** Erst der Verlauf, dann der
 * Zustand, dann der Vorfall, zuletzt die Meldung. Der Verlauf steht am Anfang,
 * weil er die Messung ist und auch dann stimmt, wenn danach etwas schiefgeht;
 * die Meldung steht am Ende, weil sie das Einzige ist, was sich nicht
 * zurücknehmen lässt.
 */
final class UptimeRecorder
{
    public function __construct(
        private readonly UptimeAlerts $alerts,
        private readonly UptimeIssues $issues,
    ) {}

    /**
     * Nimmt das Ergebnis einer Prüfung auf.
     *
     * @return UptimeCheck die geschriebene Verlaufszeile
     */
    public function record(UptimeMonitor $monitor, ProbeResult $result, ?Carbon $at = null): UptimeCheck
    {
        $at ??= Carbon::now();

        /** @var array{0: UptimeCheck, 1: UptimeOutage|null, 2: UptimeOutage|null} $recorded */
        $recorded = DB::transaction(function () use ($monitor, $result, $at): array {
            $check = UptimeCheck::record($monitor, $result, $at);

            $monitor->applyCheck($result->outcome, $at);
            $monitor->scheduleNextCheck($at);
            $monitor->save();

            return $result->isFailure()
                ? [$check, $this->onFailure($monitor, $result, $at), null]
                : [$check, null, $this->onSuccess($monitor, $at)];
        });

        [$check, $opened, $closed] = $recorded;

        // Außerhalb der Transaktion: der Eintrag und die Meldung sprechen mit
        // der Warteschlange und mit fremden Diensten. Eine offene Transaktion
        // über beides hinweg hielte die Sperre auf dem Monitor, bis ein
        // Webhook geantwortet hat — und das ist die Zeitspanne, in der die
        // nächste Prüfung ansteht.
        if ($opened !== null) {
            $this->issues->open($monitor, $opened);
            $this->alerts->down($monitor, $opened);
        }

        // Beim Ende **kein** Eingriff am Eintrag: einen Fehler-Eintrag schließt
        // ein Mensch, nicht die Überwachung. Dass es vorbei ist, steht am
        // Ausfall und geht als Entwarnung raus — den Eintrag von selbst zu
        // erledigen hieße, einen Vorfall aus der Liste zu nehmen, den noch
        // niemand angesehen hat.
        if ($closed !== null) {
            $this->alerts->recovered($monitor, $closed);
        }

        return $check;
    }

    /**
     * Eine gescheiterte Prüfung.
     *
     * Drei Fälle: der Vorfall läuft schon (mitzählen), die Schwelle ist jetzt
     * erreicht (eröffnen), oder es bleibt bei einem Aussetzer unterhalb der
     * Schwelle (nichts tun — der Zustand steht dann auf `degraded` und sagt
     * genau das).
     *
     * @return UptimeOutage|null der **neu** eröffnete Ausfall, sonst `null`
     */
    private function onFailure(UptimeMonitor $monitor, ProbeResult $result, Carbon $at): ?UptimeOutage
    {
        $open = $monitor->openOutage();

        if ($open !== null) {
            $open->noteFailure();

            return null;
        }

        if (! $monitor->needsOutage()) {
            return null;
        }

        return UptimeOutage::open($monitor, $result, $at);
    }

    /**
     * Eine erfolgreiche Prüfung.
     *
     * Der laufende Vorfall wird erst geschlossen, wenn die Schwelle für die
     * Entwarnung erreicht ist. Ohne sie zerfiele ein wackliger Dienst in
     * Dutzende Vorfälle von je zwei Minuten — und jeder davon schickte eine
     * eigene Meldung samt Entwarnung.
     *
     * @return UptimeOutage|null der **soeben** geschlossene Ausfall, sonst `null`
     */
    private function onSuccess(UptimeMonitor $monitor, Carbon $at): ?UptimeOutage
    {
        $open = $monitor->openOutage();

        if ($open === null || ! $monitor->needsRecovery()) {
            return null;
        }

        return $open->close($at) ? $open : null;
    }
}
