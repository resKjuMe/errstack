<?php

namespace App\Support\Uptime;

use App\Enums\UptimeCheckOutcome;
use App\Models\UptimeCheck;
use App\Models\UptimeMonitor;
use Illuminate\Support\Carbon;

/**
 * Die beiden Zahlen, die man nur aus vielen Messungen bekommt:
 * Verfügbarkeitsquote und Antwortzeit-Verlauf.
 *
 * **Gerechnet und nicht fortgeschrieben.** Eine mitlaufende Quote am Monitor
 * wäre billiger zu lesen, aber sie wäre auch eine zweite Wahrheit neben dem
 * Verlauf — und sie ließe sich nicht auf ein Fenster beziehen, das jemand
 * auswählt. Die Rechnung geht über einen Index, der genau dafür da ist
 * (`uptime_monitor_id`, `checked_at`), und über Zeilen, die bei einem Takt von
 * einer Minute in einem Monat gut vierzigtausend erreichen — das ist eine
 * Aggregation, keine Auswertung.
 *
 * Der Preis ist eine Handvoll Abfragen **je Monitor** auf der Übersichtsseite.
 * Das ist hier vertretbar und nicht der übliche N+1-Fehler: die Seite zeigt je
 * Monitor eine eigene Karte mit eigener Kurve, die Zahl der Monitore eines
 * Projekts liegt im einstelligen bis niedrigen zweistelligen Bereich, und jede
 * dieser Abfragen läuft über den Index. Eine gemeinsame Abfrage über alle
 * Monitore hinweg wäre eine Gruppierung über drei verschiedene Zeitfenster —
 * mehr Aufwand im Lesen als im Ausführen.
 *
 * Die Quote zählt **Prüfungen**, nicht Minuten. Der Unterschied fällt auf, wenn
 * die Anwendung selbst stand und deshalb gar nicht geprüft hat: dann fehlen die
 * Zeilen, und die Quote bleibt bei 100 %. Das ist die ehrlichere Antwort — sie
 * sagt „darüber weiß ich nichts", statt eine Nichtmessung als Erfolg oder als
 * Ausfall zu verbuchen. Dass die Überwachung selbst lief, beantwortet O5.
 */
final class UptimeStats
{
    /**
     * Die Fenster, über die die Übersicht die Quote zeigt — in Stunden.
     *
     * Drei und nicht eines: „heute", „diese Woche", „dieser Monat". Eine
     * einzelne Zahl beantwortet entweder „läuft es gerade" oder „wie war das
     * Jahr", nie beides.
     *
     * @var array<string, int>
     */
    public const WINDOWS = [
        'day' => 24,
        'week' => 24 * 7,
        'month' => 24 * 30,
    ];

    /**
     * Verfügbarkeitsquote in Prozent für jedes Fenster.
     *
     * `null`, wo es im Fenster keine einzige Messung gab — eine Quote ohne
     * Nenner ist keine Null, sondern keine Angabe.
     *
     * @return array<string, array{hours: int, availability: float|null, checks: int, failures: int}>
     */
    public function availability(UptimeMonitor $monitor, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $windows = [];

        foreach (self::WINDOWS as $key => $hours) {
            $windows[$key] = $this->window($monitor, $now->copy()->subHours($hours), $hours);
        }

        return $windows;
    }

    /**
     * @return array{hours: int, availability: float|null, checks: int, failures: int}
     */
    private function window(UptimeMonitor $monitor, Carbon $since, int $hours): array
    {
        // `toBase()`, weil hier kein Datensatz gemeint ist, sondern zwei Zahlen:
        // ein Modell mit zwei angehefteten Spalten wäre ein Objekt, das so tut,
        // als sei es eine Messung.
        $row = UptimeCheck::query()
            ->where('uptime_monitor_id', $monitor->id)
            ->since($since)
            ->toBase()
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when outcome = ? then 1 else 0 end) as up', [UptimeCheckOutcome::Up->value])
            ->first();

        $total = (int) ($row?->total ?? 0);
        $up = (int) ($row?->up ?? 0);

        return [
            'hours' => $hours,
            'availability' => $total === 0 ? null : round($up / $total * 100, 3),
            'checks' => $total,
            'failures' => $total - $up,
        ];
    }

    /**
     * Der Antwortzeit-Verlauf: die letzten Messungen, älteste zuerst.
     *
     * Gelesen wird absteigend und danach umgedreht — nur so greift der Index,
     * und nur so bekommt man die **letzten** N statt der ersten. Die Umkehrung
     * geschieht in PHP über höchstens {@see UptimeMonitor::HISTORY_LIMIT}
     * Zeilen; sie in SQL zu erzwingen wäre eine Unterabfrage für nichts.
     *
     * Gescheiterte Prüfungen bleiben in der Liste, aber ohne Zeit. Sie
     * herauszufiltern ergäbe eine glatte Kurve über einen Ausfall hinweg — die
     * Lücke ist die Aussage.
     *
     * @return list<array{at: string, ms: int|null, ok: bool}>
     */
    public function responseTimes(UptimeMonitor $monitor, int $limit = UptimeMonitor::HISTORY_LIMIT): array
    {
        return $monitor->checks()
            ->orderByDesc('checked_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['checked_at', 'response_time_ms', 'outcome'])
            ->reverse()
            ->values()
            ->map(fn (UptimeCheck $check): array => [
                'at' => $check->checked_at->toIso8601String(),
                'ms' => $check->outcome->isFailure() ? null : $check->response_time_ms,
                'ok' => ! $check->outcome->isFailure(),
            ])
            ->all();
    }

    /**
     * Die mittlere Antwortzeit erfolgreicher Prüfungen im Fenster.
     *
     * Nur erfolgreiche: eine gescheiterte Prüfung hat entweder gar keine Zeit
     * oder die Zeitgrenze als Zeit, und beides zöge den Mittelwert in eine
     * Aussage, die niemand meint.
     */
    public function averageResponseTime(UptimeMonitor $monitor, Carbon $since): ?int
    {
        $average = $monitor->checks()
            ->since($since)
            ->where('outcome', UptimeCheckOutcome::Up)
            ->whereNotNull('response_time_ms')
            ->avg('response_time_ms');

        return $average === null ? null : (int) round((float) $average);
    }
}
