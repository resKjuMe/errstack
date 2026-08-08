<?php

namespace App\Support\Performance\Trends;

use App\Models\Deploy;
use App\Models\Project;
use App\Support\Releases\DeployMarkers;
use Carbon\CarbonImmutable;

/**
 * Der wahrscheinliche Verursacher eines Bruchs: die Auslieferung, die zeitlich
 * dazu passt.
 *
 * **„Wahrscheinlich" ist wörtlich gemeint.** Ein zeitlicher Zusammenhang ist
 * kein Beweis — es kann ebenso ein neuer Kunde sein, ein Index, der gekippt
 * ist, oder ein Nachbardienst. Genau deshalb steht die Auslieferung als Angabe
 * neben dem Bruch und nicht in seiner Überschrift: sie ist der erste Ort zum
 * Nachsehen, nicht das Ergebnis.
 *
 * **Das Zeitfenster ist eng.** Wer weit genug zurücksieht, findet in einer
 * Anwendung mit täglichen Auslieferungen immer eine — und eine Zuordnung, die
 * immer eine findet, sagt nichts. Gesucht wird deshalb nur zwischen der Stunde
 * vor dem Bruch und dem Ende der Bruchstunde selbst: eine Auslieferung wirkt
 * ab dem Augenblick, in dem sie draußen ist, und der Bruchpunkt ist auf die
 * Stunde genau bestimmt ({@see TrendSeries}).
 *
 * **Die Umgebung entscheidet mit**, wie bei den Markierungen der
 * Verlaufsgrafiken ({@see DeployMarkers}): eine
 * Auslieferung nach `staging` erklärt keinen Bruch in der Produktion.
 */
final class TrendCause
{
    /**
     * Wie weit vor dem Bruchpunkt eine Auslieferung noch in Frage kommt.
     *
     * Eine Stunde: der Bruchpunkt ist der Anfang der ersten Stunde **nach** dem
     * Umschlag, eine Auslieferung in der Stunde davor ist also genau die, deren
     * Wirkung ab dann in den Zahlen steht.
     */
    public const LEAD_HOURS = 1;

    /**
     * Die Auslieferung zu einem Bruchpunkt — oder `null`, wenn keine passt.
     *
     * Eine Abfrage je Feststellung und keine gemeinsame für alle: Feststellungen
     * sind selten (eine Handvoll je Durchlauf, meistens keine), und eine
     * Sammelabfrage über Zeitfenster verschiedener Transaktionen wäre für dieses
     * Mengenverhältnis der aufwendigere Weg zu demselben Ergebnis.
     */
    public static function forBreakpoint(Project $project, string $environment, CarbonImmutable $at): ?Deploy
    {
        return Deploy::query()
            ->join('environments', 'environments.id', '=', 'deploys.environment_id')
            ->where('deploys.project_id', $project->id)
            ->where('environments.name', $environment)
            ->where('deploys.finished_at', '>=', $at->subHours(self::LEAD_HOURS))
            // Bis zum Ende der Bruchstunde: eine Auslieferung mitten in ihr hat
            // die zweite Hälfte der Stunde bereits geprägt.
            ->where('deploys.finished_at', '<', $at->addHour())
            // Die späteste zuerst: liegen zwei im Fenster, ist die jüngere die
            // naheliegendere Erklärung. Die Spalten ausdrücklich mit Tabelle —
            // durch den Verbund gibt es `id` zweimal, und ein unqualifiziertes
            // `id` ist in MySQL kein Sortierkriterium, sondern ein Fehler.
            ->orderByDesc('deploys.finished_at')
            ->orderByDesc('deploys.id')
            ->select('deploys.*')
            ->first();
    }
}
