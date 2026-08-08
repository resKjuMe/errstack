<?php

namespace App\Support\Issues;

use App\Enums\CountPeriod;
use App\Enums\IssueActivityType;
use App\Enums\IssueStatus;
use App\Models\Issue;
use App\Models\IssueActivity;
use App\Models\IssueCount;
use App\Support\Alerts\MetricAlertSweep;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Der Durchlauf, der die Wichtigkeit fortschreibt und Eskalationen feststellt
 * (S11).
 *
 * **Im Hintergrund und nicht im Web-Request** — die Zusage der Aufgabe, und sie
 * ist keine Formsache. Die Ableitung braucht je Eintrag den Verlauf zweier
 * Tage; sie beim Anzeigen der Liste zu rechnen hieße, fünfzig Zeitreihen zu
 * lesen, bevor die erste Zeile erscheint. Vor allem aber wäre sie dann an das
 * Hinsehen gebunden: die Eskalation eines stummgeschalteten Fehlers fiele
 * ausgerechnet dann nicht auf, wenn niemand die Seite offen hat.
 *
 * **Angesehen wird, was sich bewegt hat.** Der Durchlauf geht nicht über alle
 * Einträge, sondern über die der letzten zwei Fenster. Das erste Fenster ist
 * das, aus dem gerechnet wird; das zweite ist der Grund, warum ein Eintrag auch
 * wieder **absteigt**: ein Fehler, der gestern hoch war und seither schweigt,
 * bekommt genau einen letzten Durchgang, in dem sein Rückgang zählt. Danach
 * steht er unten und wird nicht mehr angefasst — was still ist, muss nicht
 * jede Viertelstunde neu bewertet werden.
 *
 * **Erledigte Einträge bleiben, wie sie sind.** Ihre Wichtigkeit ist die des
 * Augenblicks, in dem jemand sie geschlossen hat; sie weiterzurechnen hieße,
 * eine Zahl zu pflegen, die niemand mehr liest.
 *
 * **Ein Eintrag, der stolpert, hält die anderen nicht auf** — dieselbe Regel
 * wie beim Schwellwert-Durchlauf ({@see MetricAlertSweep}).
 */
final class IssuePrioritySweep
{
    /**
     * Das Fenster, aus dem gerechnet wird.
     *
     * 24 Stunden, weil jeder Tagesverlauf einen Berg und ein Tal hat: ein
     * kürzeres Fenster würde jeden Fehler nachts herunter- und mittags
     * heraufstufen, und die Liste sortierte sich dann nach Uhrzeit.
     */
    public const WINDOW_HOURS = 24;

    /**
     * Woraus sich der erwartete Verlauf eines stummgeschalteten Eintrags
     * ergibt.
     *
     * Sieben Tage, damit eine ganze Woche im Durchschnitt steckt — mit einem
     * kürzeren Zeitraum wäre der Montagmorgen jedes Mal eine Eskalation.
     */
    public const BASELINE_DAYS = 7;

    /**
     * Wie lange nach einer Eskalation Ruhe ist.
     *
     * Sie greift nur, wenn jemand denselben Eintrag mitten in der Welle erneut
     * stummschaltet: sonst ist er nach der ersten Feststellung ohnehin offen
     * und kommt nicht wieder in die Prüfung. Wer in dieser Lage ein zweites Mal
     * stummschaltet, meint es — und soll nicht zehn Minuten später wieder
     * geweckt werden.
     */
    public const COOLDOWN_HOURS = 24;

    /**
     * Wie viele Einträge auf einmal betrachtet werden.
     *
     * Je Block sind es wenige Abfragen über alle Einträge zusammen und nicht
     * eine je Eintrag — bei 50.000 Fehlern ist das der Unterschied zwischen
     * einem Durchlauf und einem Zeitlimit.
     */
    private const CHUNK = 500;

    public function __construct(private readonly IssueEscalationNotifier $notifier) {}

    /**
     * Ein Durchlauf. Gibt zurück, was dabei herauskam — die Konsole schreibt es
     * hin, die Tests prüfen es.
     *
     * @return array{examined: int, changed: int, escalated: int, failed: int}
     */
    public function run(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        $result = ['examined' => 0, 'changed' => 0, 'escalated' => 0, 'failed' => 0];

        Issue::query()
            // Zusammengeführte Untergruppen stehen nicht für sich: ihre Zahlen
            // sind im Kopf enthalten, und eine eigene Wichtigkeit würde in
            // keiner Liste erscheinen (S9).
            ->standalone()
            ->whereIn('status', [IssueStatus::Unresolved, IssueStatus::Ignored])
            ->where('last_seen', '>=', $now->subHours(2 * self::WINDOW_HOURS))
            ->with('project.organization')
            ->chunkById(self::CHUNK, function (Collection $issues) use ($now, &$result): void {
                $counts = $this->countsFor($issues, $now);
                $baselines = $this->baselinesFor($issues, $now);

                foreach ($issues as $issue) {
                    $result['examined']++;

                    try {
                        if ($this->escalate($issue, $baselines[$issue->id] ?? null, $now)) {
                            $result['escalated']++;
                        }

                        if ($this->reprioritize($issue, $counts[$issue->id] ?? ['recent' => 0, 'previous' => 0], $now)) {
                            $result['changed']++;
                        }
                    } catch (Throwable $e) {
                        $result['failed']++;

                        Log::error('Wichtigkeit konnte nicht ermittelt werden.', [
                            'issue_id' => $issue->id,
                            'project_id' => $issue->project_id,
                            'exception' => $e,
                        ]);
                    }
                }
            }, 'issues.id', 'id');

        return $result;
    }

    /**
     * Schreibt die Wichtigkeit eines Eintrags fort — und `false`, wenn nichts
     * zu tun war.
     *
     * **Von Hand geht vor.** Der Schalter `priority_locked` ist die einzige
     * Stelle, an der dieser Durchlauf haltmacht; alles andere rechnet er neu.
     *
     * **Geschrieben wird nur die Änderung.** Ein `update` je Eintrag und
     * Durchlauf wäre bei stündlicher Ausführung ein Verlauf voller Zeilen, die
     * „hoch → hoch" sagen — und `updated_at` wanderte bei jedem Fehler der
     * Anwendung im Viertelstundentakt.
     *
     * @param  array{recent: int, previous: int}  $counts
     */
    private function reprioritize(Issue $issue, array $counts, CarbonImmutable $now): bool
    {
        if ($issue->priority_locked) {
            return false;
        }

        $score = IssuePriorityScore::derive(
            $issue->level,
            $counts['recent'],
            $counts['previous'],
            $issue->users_seen,
            $issue->first_seen->greaterThanOrEqualTo($now->subHours(self::WINDOW_HOURS)),
        );

        if ($score->priority === $issue->priority) {
            return false;
        }

        $previous = $issue->priority;

        $written = Issue::query()
            ->whereKey($issue->id)
            // Zwischen dem Lesen und dem Schreiben kann jemand von Hand
            // eingegriffen haben. Die Bedingung ist der Unterschied zwischen
            // „die Automatik überschreibt das nicht" als Zusage und als Absicht.
            ->where('priority_locked', false)
            ->update(['priority' => $score->priority, 'updated_at' => $now]);

        if ($written === 0) {
            return false;
        }

        IssueActivity::query()->create([
            'issue_id' => $issue->id,
            'project_id' => $issue->project_id,
            'user_id' => null,
            'actor_name' => null,
            'type' => IssueActivityType::PriorityChanged,
            // Die Herleitung wandert mit in den Verlauf: die Stufe allein wäre
            // eine Behauptung, und nachrechnen ließe sie sich später nicht mehr
            // — die Zahlen von heute sind morgen andere.
            'data' => [
                'mode' => 'derived',
                'priority' => $score->priority->value,
                'previous' => $previous->value,
                'score' => $score->score,
                'reasons' => $score->reasons,
            ],
        ]);

        $issue->priority = $score->priority;

        return true;
    }

    /**
     * Prüft einen stummgeschalteten Eintrag gegen seinen eigenen Verlauf und
     * holt ihn zurück, wenn er aus dem Ruder gelaufen ist.
     *
     * @param  array{observed: int, expected: float}|null  $baseline
     */
    private function escalate(Issue $issue, ?array $baseline, CarbonImmutable $now): bool
    {
        if ($baseline === null || $issue->status !== IssueStatus::Ignored) {
            return false;
        }

        if ($issue->escalated_at !== null
            && $issue->escalated_at->greaterThan($now->subHours(self::COOLDOWN_HOURS))) {
            return false;
        }

        $escalation = IssueEscalation::check($baseline['observed'], $baseline['expected']);

        if ($escalation === null || ! IssueActions::escalate($issue, $escalation, $now)) {
            return false;
        }

        // Erst zurückholen, dann melden: wer die Nachricht öffnet, soll den
        // Eintrag offen vorfinden und nicht stummgeschaltet.
        $this->notifier->report($issue, $escalation);

        return true;
    }

    /**
     * Die Ereignisse der beiden Fenster, je Eintrag — in **einer** Abfrage über
     * den ganzen Block.
     *
     * Gezählt wird über die Stundenreihe ({@see IssueCount}) und nicht über die
     * Ereignisse: die Reihe überlebt das Aufräumen der Meldungen, und die Frage
     * „wie oft in 24 Stunden" ist an ihr eine Summe über 24 Zeilen statt ein
     * Durchgang durch Millionen.
     *
     * @param  Collection<int, Issue>  $issues
     * @return array<int, array{recent: int, previous: int}>
     */
    private function countsFor(Collection $issues, CarbonImmutable $now): array
    {
        $recentFrom = CountPeriod::Hour->windowFor($now->subHours(self::WINDOW_HOURS));
        $from = CountPeriod::Hour->windowFor($now->subHours(2 * self::WINDOW_HOURS));

        // Untergruppen zählen für ihren Kopf mit — dieselbe Zuordnung, mit der
        // die Verlaufsgrafik gezeichnet wird ({@see IssueSeries::owners()}). Ohne
        // sie stünde ein zusammengeführter Fehler in der Bewertung still,
        // während seine Zahlen weiterlaufen.
        $owner = IssueSeries::owners($issues->modelKeys());

        $rows = IssueCount::query()
            ->selectRaw('issue_id')
            ->selectRaw('sum(case when window_start >= ? then event_count else 0 end) as recent', [$recentFrom])
            ->selectRaw('sum(case when window_start < ? then event_count else 0 end) as previous', [$recentFrom])
            ->where('period', CountPeriod::Hour)
            ->whereIn('issue_id', array_keys($owner))
            ->where('window_start', '>=', $from)
            ->groupBy('issue_id')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $id = $owner[(int) $row->issue_id];

            $counts[$id] ??= ['recent' => 0, 'previous' => 0];
            $counts[$id]['recent'] += (int) $row->recent;
            $counts[$id]['previous'] += (int) $row->previous;
        }

        return $counts;
    }

    /**
     * Für die stummgeschalteten Einträge des Blocks: was in der zuletzt
     * vollständigen Stunde ankam und was ihr Verlauf erwarten ließ.
     *
     * **Die laufende Stunde bleibt draußen.** Sie ist erst zum Teil gefüllt und
     * würde jede Eskalation um bis zu eine Stunde verzögern — schlimmer noch,
     * sie würde in ihrer ersten Minute jeden Eintrag als abgeklungen ausweisen.
     *
     * **Der Erwartungswert teilt durch alle Stunden des Zeitraums**, nicht nur
     * durch die, in denen etwas ankam: Stille gehört zum Verlauf. Ein Eintrag,
     * der einmal in der Woche zwanzigmal auftritt, hat den Erwartungswert 0,12
     * je Stunde und nicht 20 — sonst wäre gerade der seltene Ausreißer nie
     * einer.
     *
     * @param  Collection<int, Issue>  $issues
     * @return array<int, array{observed: int, expected: float}>
     */
    private function baselinesFor(Collection $issues, CarbonImmutable $now): array
    {
        $ignored = $issues
            ->filter(static fn (Issue $issue): bool => $issue->status === IssueStatus::Ignored)
            ->modelKeys();

        if ($ignored === []) {
            return [];
        }

        $hour = CountPeriod::Hour->windowFor($now)->subHour();
        $from = $hour->subDays(self::BASELINE_DAYS);
        $owner = IssueSeries::owners($ignored);

        $rows = IssueCount::query()
            ->selectRaw('issue_id')
            ->selectRaw('sum(case when window_start = ? then event_count else 0 end) as observed', [$hour])
            // Der Vergleichszeitraum endet **vor** der betrachteten Stunde:
            // eine Welle, die ihren eigenen Erwartungswert anhebt, wäre keine.
            ->selectRaw('sum(case when window_start < ? then event_count else 0 end) as baseline', [$hour])
            ->where('period', CountPeriod::Hour)
            ->whereIn('issue_id', array_keys($owner))
            ->where('window_start', '>=', $from)
            ->where('window_start', '<=', $hour)
            ->groupBy('issue_id')
            ->get();

        $hours = self::BASELINE_DAYS * 24;
        $totals = [];

        foreach ($rows as $row) {
            $id = $owner[(int) $row->issue_id];

            $totals[$id] ??= ['observed' => 0, 'baseline' => 0];
            $totals[$id]['observed'] += (int) $row->observed;
            $totals[$id]['baseline'] += (int) $row->baseline;
        }

        return array_map(static fn (array $total): array => [
            'observed' => $total['observed'],
            'expected' => $total['baseline'] / $hours,
        ], $totals);
    }
}
