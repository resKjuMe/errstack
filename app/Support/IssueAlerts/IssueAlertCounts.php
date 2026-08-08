<?php

namespace App\Support\IssueAlerts;

use App\Enums\CountPeriod;
use App\Models\Event;
use App\Models\EventGroup;
use App\Models\Issue;
use App\Models\IssueCount;
use App\Models\IssueUser;
use Carbon\CarbonImmutable;

/**
 * Die Zahlen, die eine Bedingung braucht — und zwar aus der Quelle, die zur
 * Frage passt.
 *
 * Zwei Quellen, weil die Fragen zwei verschiedene Auflösungen verlangen. „Wie
 * oft in den letzten fünf Minuten?" ist an den vorberechneten Zählern
 * ({@see IssueCount}) nicht zu beantworten: die kennen Stunden und Tage. „Wie
 * war das in derselben Spanne der Vorwoche?" ist am Ereignisstrom nicht zu
 * beantworten: der ist bis dahin längst weggeräumt.
 *
 * Beide Wege meinen den Fehler samt seiner beigetretenen Einträge
 * ({@see Issue::memberIds()}) — sonst stünde ein zusammengeführter Fehler in
 * der Alarmierung still, obwohl er auftritt.
 */
final class IssueAlertCounts
{
    /**
     * Wie oft der Eintrag in den letzten `$minutes` Minuten auftrat.
     *
     * Gezählt wird am Ereignisstrom und über den Index
     * `(event_group_id, occurred_at)`; die Zeitgrenze steht deshalb im
     * `where` und nicht in einer nachgelagerten Prüfung.
     */
    public function events(Issue $issue, int $minutes, CarbonImmutable $now): int
    {
        $groups = EventGroup::query()
            ->whereIn('issue_id', $issue->memberIds())
            ->pluck('id');

        if ($groups->isEmpty()) {
            return 0;
        }

        return Event::query()
            ->whereIn('event_group_id', $groups)
            ->where('occurred_at', '>=', $now->subMinutes($minutes))
            ->count();
    }

    /**
     * Wie viele Betroffene in den letzten `$minutes` Minuten **hinzukamen**.
     *
     * Hinzukamen und nicht „waren betroffen": gespeichert ist je Betroffenem
     * sein erstes Auftreten, nicht sein letztes. Für die Frage, die eine
     * Alarmregel stellt — greift das gerade um sich? — ist das die richtige
     * Zahl; „schon immer betroffen" wäre der Zähler am Eintrag.
     */
    public function users(Issue $issue, int $minutes, CarbonImmutable $now): int
    {
        return IssueUser::query()
            ->whereIn('issue_id', $issue->memberIds())
            ->where('first_seen', '>=', $now->subMinutes($minutes))
            ->count();
    }

    /**
     * Die Veränderung gegenüber derselben Spanne der Vorwoche, in Prozent.
     *
     * `null`, wenn es in der Vorwoche nichts gab, womit sich vergleichen ließe:
     * „unendlich viel mehr als nichts" ist keine Aussage, an der eine Schwelle
     * hängen darf — und ein frisch aufgetretener Fehler würde sonst jede
     * Prozentregel reißen.
     */
    public function percentChange(Issue $issue, int $hours, CarbonImmutable $now): ?float
    {
        $current = $this->hourly($issue, $now->subHours($hours), $now);
        $baselineEnd = $now->subDays(7);
        $baseline = $this->hourly($issue, $baselineEnd->subHours($hours), $baselineEnd);

        if ($baseline === 0) {
            return null;
        }

        return ($current - $baseline) / $baseline * 100;
    }

    /**
     * Die Summe der Stundenzähler in einer Spanne.
     *
     * Die Grenzen werden auf volle Stunden abgeschnitten, weil die Zeilen so
     * abgelegt sind. Der Anfang wird dadurch großzügiger, das Ende ebenfalls —
     * beide Spannen sind gleich behandelt, und der Vergleich bleibt damit
     * einer zwischen Gleichem.
     */
    private function hourly(Issue $issue, CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) IssueCount::query()
            ->whereIn('issue_id', $issue->memberIds())
            ->where('period', CountPeriod::Hour)
            ->where('window_start', '>=', CountPeriod::Hour->windowFor($from))
            ->where('window_start', '<=', CountPeriod::Hour->windowFor($to))
            ->sum('event_count');
    }
}
