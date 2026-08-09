<?php

namespace App\Support\Releases\Health;

use App\Models\Release;
use App\Models\ReleaseSessionCount;
use App\Models\ReleaseSessionUser;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

/**
 * Die Leseseite der Release-Gesundheit: aus den Zählern werden Kennzahlen.
 *
 * Die einzige Stelle, die weiß, wie aus Sitzungszahlen eine Crash-Free-Rate und
 * eine Verbreitung werden. Getrennt vom Schreiben ({@see SessionRecorder}) und
 * getrennt von der Anzeige (R8): was hier herauskommt, ist dieselbe Auskunft
 * für die Übersicht, für die Schnittstelle und für die Schwellwert-Alarme —
 * eine zweite Rechnung an anderer Stelle wäre eine, die der ersten eines Tages
 * widerspricht.
 *
 * **Drei Abfragen je Auslieferung, unabhängig von der Datenmenge.** Die
 * Sitzungen kommen aus den vorberechneten Zeilen, die Menschen aus den
 * vorverdichteten Nutzer-Zeilen (`count(distinct …)`), die Vergleichszahl des
 * ganzen Projekts aus denselben beiden Tabellen. Über die Einzelsitzungen
 * gerechnet wäre jede davon ein Durchlauf über jeden Start jeder App.
 */
final class ReleaseHealth
{
    /**
     * Die Kennzahlen einer Auslieferung.
     *
     * @param  string|null  $environment  Auf eine Umgebung eingeschränkt, oder
     *                                    `null` für alle. Die Einschränkung gilt
     *                                    auch für den Nenner der Verbreitung —
     *                                    sonst verglichen sich die Nutzer einer
     *                                    Testumgebung mit denen der ganzen Welt.
     */
    public function summarize(
        Release $release,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?string $environment = null,
    ): ReleaseHealthSummary {
        [$projectSessions, $projectUsers] = $this->projectTotals($release->project_id, $from, $to, $environment);

        return $this->summary($release, $from, $to, $environment, $projectSessions, $projectUsers);
    }

    /**
     * Die Kennzahlen einer Auslieferung neben denen der Vorversion.
     *
     * Der Vergleich ist der eigentliche Zweck der Zahlen: „99,2 % absturzfrei"
     * allein sagt niemandem, ob die Auslieferung gut war. Erst „vorher waren es
     * 99,8 %" macht daraus eine Aussage.
     *
     * Die Vergleichszahlen des Projekts werden **einmal** geholt und für beide
     * Versionen verwendet: es ist derselbe Zeitraum und dieselbe Umgebung, und
     * zweimal gefragt wäre es dieselbe Antwort.
     *
     * @return array{current: ReleaseHealthSummary, previous: ReleaseHealthSummary|null}
     */
    public function compare(
        Release $release,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?string $environment = null,
    ): array {
        [$projectSessions, $projectUsers] = $this->projectTotals($release->project_id, $from, $to, $environment);

        $previous = $this->previous($release);

        return [
            'current' => $this->summary($release, $from, $to, $environment, $projectSessions, $projectUsers),
            'previous' => $previous === null
                ? null
                : $this->summary($previous, $from, $to, $environment, $projectSessions, $projectUsers),
        ];
    }

    /**
     * Die Auslieferung, die unmittelbar vor dieser kommt.
     *
     * **Dieselbe Rangfolge wie die Versionsliste** ({@see Release::isNewerThan()}),
     * und das ist der Punkt: „die Vorversion" muss dieselbe sein, die in der
     * Liste eine Zeile weiter unten steht. Eine zweite Vorstellung von „davor"
     * ergäbe einen Vergleich, den niemand nachvollziehen kann.
     *
     * Gerechnet wird in PHP und nicht in SQL: die Rangfolge steht bereits als
     * Methode am Modell, und sie in eine `order by`-Kette zu übersetzen hieße,
     * sie ein zweites Mal zu schreiben. Geholt werden dafür die Versionen des
     * Projekts — eine schmale Abfrage über wenige Spalten, und ihre Zahl wächst
     * mit den Auslieferungen und nicht mit dem Verkehr.
     */
    public function previous(Release $release): ?Release
    {
        $candidates = Release::query()
            ->where('project_id', $release->project_id)
            ->whereKeyNot($release->getKey())
            // Nur die Spalten, aus denen sich der Rang ergibt — und die
            // Versionsangabe, weil der Aufrufer sie anzeigt.
            ->select([
                'id',
                'project_id',
                'version',
                'sort_major',
                'sort_minor',
                'sort_patch',
                'sort_prerelease',
                'first_event_at',
            ])
            ->get();

        $previous = null;

        foreach ($candidates as $candidate) {
            if ($release->isNewerThan($candidate) && ($previous === null || $candidate->isNewerThan($previous))) {
                $previous = $candidate;
            }
        }

        return $previous;
    }

    /**
     * Die Kennzahlen einer Auslieferung, mit bereits bekannten Vergleichszahlen.
     */
    private function summary(
        Release $release,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?string $environment,
        int $projectSessions,
        int $projectUsers,
    ): ReleaseHealthSummary {
        $row = $this->window(ReleaseSessionCount::query()->toBase(), $release->project_id, $from, $to, $environment)
            ->where('release_id', $release->getKey())
            ->selectRaw(implode(', ', ReleaseSessionCount::sumExpressions()))
            ->first();

        $users = $this->window(ReleaseSessionUser::query()->toBase(), $release->project_id, $from, $to, $environment)
            ->where('release_id', $release->getKey())
            ->selectRaw(implode(', ', [
                'count(distinct user_key) as users',
                'count(distinct case when crashed_count > 0 then user_key end) as crashed_users',
                'count(distinct case when errored_count > 0 or crashed_count > 0 or abnormal_count > 0'
                    .' then user_key end) as unhealthy_users',
            ]))
            ->first();

        /** @var array<string, mixed> $counts */
        $counts = $row === null ? [] : (array) $row;

        /** @var array<string, mixed> $affected */
        $affected = $users === null ? [] : (array) $users;

        return new ReleaseHealthSummary(
            $release,
            ReleaseSessionCount::tallyFromRow($counts),
            (int) ($affected['users'] ?? 0),
            (int) ($affected['crashed_users'] ?? 0),
            (int) ($affected['unhealthy_users'] ?? 0),
            $projectSessions,
            $projectUsers,
        );
    }

    /**
     * Sitzungen und Menschen des ganzen Projekts im Zeitraum — der Nenner der
     * Verbreitung.
     *
     * @return array{0: int, 1: int}
     */
    private function projectTotals(int $projectId, CarbonImmutable $from, CarbonImmutable $to, ?string $environment): array
    {
        $sessions = $this->window(ReleaseSessionCount::query()->toBase(), $projectId, $from, $to, $environment)
            ->sum('session_count');

        $users = $this->window(ReleaseSessionUser::query()->toBase(), $projectId, $from, $to, $environment)
            ->distinct()
            ->count('user_key');

        return [(int) $sessions, $users];
    }

    /**
     * Projekt, Zeitraum und Umgebung — die Einschränkung, die jede Abfrage hier
     * teilt.
     *
     * Oben offen (`from <= t < to`), wie bei den Alarm-Fenstern: sonst zählte
     * das Fenster an der Grenze in zwei aufeinanderfolgenden Zeiträumen mit.
     */
    private function window(
        Builder $query,
        int $projectId,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?string $environment,
    ): Builder {
        $query
            ->where('project_id', $projectId)
            ->where('bucket_start', '>=', $from->utc())
            ->where('bucket_start', '<', $to->utc());

        if ($environment !== null) {
            $query->where('environment', $environment);
        }

        return $query;
    }
}
