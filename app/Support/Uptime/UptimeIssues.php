<?php

namespace App\Support\Uptime;

use App\Enums\GroupingSource;
use App\Events\IssueCreated;
use App\Models\EventGroup;
use App\Models\Issue;
use App\Models\UptimeMonitor;
use App\Models\UptimeOutage;
use App\Support\Ingest\Grouping\Fingerprint;
use App\Support\Performance\Detection\PerformanceIssues;
use Illuminate\Support\Str;

/**
 * Aus einem Ausfall wird ein Eintrag.
 *
 * Dieselbe Entscheidung wie bei der Leistungserkennung
 * ({@see PerformanceIssues}) und aus
 * demselben Grund: ein Ausfall soll dort auftauchen, wo ohnehin nachgesehen
 * wird. Er läuft deshalb über die vorhandene Maschinerie — Fingerabdruck →
 * Gruppe → Eintrag → zählen — und nicht daneben. Damit gelten Zustand,
 * Priorität, Zuweisung und Alarmregeln sofort, ohne dass eine dieser Funktionen
 * von Erreichbarkeit wissen müsste.
 *
 * **Ein Eintrag je Monitor, nicht je Ausfall.** Der Fingerabdruck steht auf dem
 * Monitor, nicht auf dem einzelnen Vorfall: „die Startseite war weg" ist
 * dreimal dieselbe Aussage, und drei Einträge dafür wären dieselbe Flut, gegen
 * die es die Gruppierung überhaupt gibt. Wie oft es passiert ist, sagt die
 * Häufigkeit; wann genau, sagt die Liste der Ausfälle.
 *
 * Die Kategorie ist `error` und nicht etwas Eigenes: ein Ausfall ist die
 * Antwort auf „was ist kaputt?", und genau danach sucht jemand in der
 * Fehlerliste.
 */
final class UptimeIssues
{
    /**
     * Wie lang die Überschrift eines Eintrags höchstens wird.
     */
    private const TITLE_LIMIT = 160;

    /**
     * Legt den Eintrag zu einem Ausfall an bzw. zählt den Ausfall am
     * vorhandenen mit, und verknüpft beide.
     */
    public function open(UptimeMonitor $monitor, UptimeOutage $outage): Issue
    {
        $group = EventGroup::forFingerprint($monitor->project_id, self::fingerprint($monitor));

        $issue = Issue::forUptime($group, $outage, $this->title($monitor), $monitor->url);

        $isNew = $issue->wasRecentlyCreated;

        $outage->issue_id = $issue->id;
        $outage->save();

        $issue->recordOutage($outage);

        if ($isNew) {
            event(IssueCreated::fromIssue($issue));
        }

        return $issue;
    }

    /**
     * Der Fingerabdruck eines überwachten Ziels.
     *
     * Er steht auf der **Kennung** des Monitors und nicht auf seiner Adresse:
     * die Adresse ändert sich (ein Pfad wird ergänzt, `http` wird `https`), das
     * überwachte Ding bleibt dasselbe. Über die Adresse gebildet entstünde bei
     * jeder solchen Änderung ein zweiter Eintrag, dessen Zählung bei eins
     * beginnt — und die Alarmregel, die auf den ersten zeigte, meldete nie
     * wieder etwas.
     */
    public static function fingerprint(UptimeMonitor $monitor): Fingerprint
    {
        return Fingerprint::of(GroupingSource::Uptime, ['uptime', $monitor->slug]);
    }

    /**
     * Die Überschrift: worum es geht und welches Ziel.
     *
     * Der Name des Monitors und nicht seine Adresse — er ist das, was jemand
     * hingeschrieben hat, und in einer Liste liest sich „Startseite" besser als
     * eine Adresse mit Abfrageteil. Die Adresse steht als Fehlerstelle daneben.
     */
    private function title(UptimeMonitor $monitor): string
    {
        return Str::limit(__('uptime.issue.title', ['monitor' => $monitor->name]), self::TITLE_LIMIT);
    }
}
