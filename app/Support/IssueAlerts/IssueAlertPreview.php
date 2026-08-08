<?php

namespace App\Support\IssueAlerts;

use App\Enums\IssueActivityType;
use App\Enums\IssueAlertCondition;
use App\Enums\IssueAlertMatch;
use App\Enums\IssueCategory;
use App\Enums\IssueStatus;
use App\Models\Event;
use App\Models\Issue;
use App\Models\IssueActivity;
use App\Models\IssueAlertRule;
use App\Models\Project;
use App\Support\Formats;
use Carbon\CarbonImmutable;

/**
 * Was eine Regel **jetzt** träfe — nachgesehen, bevor man sie speichert.
 *
 * Der Zweck ist die Frage „meint diese Regel wirklich das, was ich meine?", und
 * die lässt sich nicht durch Warten beantworten: eine Regel, die zu weit
 * gefasst ist, merkt man sonst erst an dreihundert Nachrichten.
 *
 * **Die Vorschau ist kein Nachspielen des Verlaufs, und sie gibt auch nicht
 * vor, eines zu sein.** Ein Nachspielen bräuchte jedes Ereignis der letzten
 * Wochen samt der Reihenfolge, in der es ankam — die Meldungen sind dafür zu
 * kurz aufbewahrt. Stattdessen wird jede Bedingung in ihre **rückblickende**
 * Form gebracht: aus „tritt zum ersten Mal auf" wird „ist in den letzten Tagen
 * zum ersten Mal aufgetreten", aus „reißt seine Stummschaltung" wird „ist in
 * den letzten Tagen aufgewacht". Die zählenden Bedingungen brauchen keine
 * Übersetzung; sie rechnen ohnehin über ein Zeitfenster.
 *
 * Die Häufigkeitsbegrenzung bleibt dabei außen vor. Sie sagt, wie oft gemeldet
 * wird, nicht, was getroffen wird — und die Vorschau beantwortet die zweite
 * Frage.
 */
final class IssueAlertPreview
{
    /**
     * Wie weit zurückgesehen wird.
     *
     * Zwei Wochen: lang genug, dass auch eine Regel auf seltene Fehler etwas
     * anzeigt, kurz genug, dass die Liste noch von der Gegenwart handelt.
     */
    public const LOOKBACK_DAYS = 14;

    /**
     * Wie viele Fehler geprüft und wie viele davon gezeigt werden.
     *
     * Geprüft wird eine begrenzte Menge, weil die Vorschau an einem
     * Formular hängt und nicht an einem Bericht: die jüngsten zweihundert
     * Fehler eines Projekts sind die, über die jemand gerade nachdenkt.
     */
    public const SCAN_LIMIT = 200;

    public const SHOW_LIMIT = 25;

    public function __construct(private readonly IssueAlertCounts $counts) {}

    /**
     * @return array{lookbackDays: int, scanned: int, matched: int, truncated: bool, issues: list<array<string, mixed>>}
     */
    public function build(IssueAlertRule $rule, Project $project): array
    {
        $now = CarbonImmutable::now();
        $since = $now->subDays(self::LOOKBACK_DAYS);

        $issues = Issue::query()
            ->where('project_id', $project->id)
            ->ofCategory(IssueCategory::Error)
            ->standalone()
            ->where('last_seen', '>=', $since)
            ->latestFirst()
            ->limit(self::SCAN_LIMIT)
            ->get();

        $conditions = $rule->parsedConditions();
        $filters = $rule->parsedFilters();
        $awoken = $this->awoken($issues->pluck('id')->all(), $since);
        $filterEngine = new IssueAlertFilters;

        $matched = [];

        foreach ($issues as $issue) {
            $reasons = $this->reasons($conditions, $rule->condition_match, $issue, $since, $now, $awoken);

            if ($reasons === []) {
                continue;
            }

            $event = $this->latestEvent($issue);

            if ($event !== null && $filters !== []) {
                $context = new IssueAlertContext($issue, $event, false, false, $now);

                if (! $filterEngine->passes($filters, $rule->filter_match, $context)) {
                    continue;
                }
            }

            $matched[] = $this->row($issue, $reasons);
        }

        return [
            'lookbackDays' => self::LOOKBACK_DAYS,
            'scanned' => $issues->count(),
            'matched' => count($matched),
            'truncated' => count($matched) > self::SHOW_LIMIT,
            'issues' => array_slice($matched, 0, self::SHOW_LIMIT),
        ];
    }

    /**
     * Die Anlässe, aus denen dieser Fehler in der Vorschau steht.
     *
     * @param  list<RuleCondition>  $conditions
     * @param  array<int, bool>  $awoken
     * @return list<string>
     */
    private function reasons(
        array $conditions,
        IssueAlertMatch $match,
        Issue $issue,
        CarbonImmutable $since,
        CarbonImmutable $now,
        array $awoken,
    ): array {
        if ($conditions === []) {
            return [];
        }

        $reasons = [];

        foreach ($conditions as $condition) {
            if ($this->matches($condition, $issue, $since, $now, $awoken)) {
                $reasons[] = $condition->type->label();

                if ($match === IssueAlertMatch::Any) {
                    return $reasons;
                }

                continue;
            }

            if ($match === IssueAlertMatch::All) {
                return [];
            }
        }

        return $reasons;
    }

    /**
     * @param  array<int, bool>  $awoken
     */
    private function matches(
        RuleCondition $condition,
        Issue $issue,
        CarbonImmutable $since,
        CarbonImmutable $now,
        array $awoken,
    ): bool {
        return match ($condition->type) {
            IssueAlertCondition::NewIssue => $issue->first_seen->greaterThanOrEqualTo($since),
            IssueAlertCondition::Regression => self::regressed($issue, $since),
            IssueAlertCondition::Escalation => $awoken[$issue->id] ?? false,
            IssueAlertCondition::Frequency => $this->counts->events($issue, $condition->windowMinutes(), $now) > $condition->value,
            IssueAlertCondition::UserFrequency => $this->counts->users($issue, $condition->windowMinutes(), $now) > $condition->value,
            IssueAlertCondition::PercentChange => $this->percentChanged($condition, $issue, $now),
        };
    }

    /**
     * Ist dieser Eintrag im betrachteten Zeitraum zurückgekommen?
     *
     * Zwei Fälle, seit es die Rückfallerkennung (S8) gibt. Der erste ist der
     * übliche: der Eintrag ist von selbst wieder aufgegangen, und der Zeitpunkt
     * steht an ihm. Der zweite bleibt daneben stehen, weil er nicht dasselbe
     * ist — eine Meldung aus einer noch laufenden alten Fassung macht „erledigt
     * in 1.4.2" nicht rückgängig, ist der Regel aber derselbe Anlass: der
     * behobene Fehler trifft weiter ein.
     *
     * Die Vorschau kann dabei nur den **jetzigen** Stand befragen: sie zählt
     * nach, was gewesen wäre, und hat dafür keine Historie der Zustände. Ein
     * Eintrag, der zurückkam und seither erneut erledigt wurde, fehlt ihr
     * deshalb — dieselbe Ungenauigkeit wie bei den übrigen Bedingungen, die
     * über Zähler gehen.
     */
    private static function regressed(Issue $issue, CarbonImmutable $since): bool
    {
        if ($issue->regressed_at !== null) {
            return $issue->regressed_at->greaterThanOrEqualTo($since);
        }

        return $issue->status === IssueStatus::Resolved
            && $issue->resolved_at !== null
            && $issue->last_seen->greaterThan($issue->resolved_at);
    }

    private function percentChanged(RuleCondition $condition, Issue $issue, CarbonImmutable $now): bool
    {
        $change = $this->counts->percentChange($issue, max(1, $condition->window), $now);

        return $change !== null && $change >= $condition->value;
    }

    /**
     * Welche der geprüften Fehler im Rückblick aus ihrer Stummschaltung
     * aufgewacht sind.
     *
     * In einer Abfrage für alle statt einer je Fehler: die Vorschau sieht bis
     * zu zweihundert Einträge an, und zweihundert Abfragen wären an einem
     * Formular deutlich zu merken.
     *
     * @param  list<int>  $issueIds
     * @return array<int, bool>
     */
    private function awoken(array $issueIds, CarbonImmutable $since): array
    {
        if ($issueIds === []) {
            return [];
        }

        return IssueActivity::query()
            ->whereIn('issue_id', $issueIds)
            ->where('type', IssueActivityType::IgnoreExpired)
            ->where('created_at', '>=', $since)
            ->pluck('issue_id')
            ->mapWithKeys(static fn (int $id): array => [$id => true])
            ->all();
    }

    /**
     * Das jüngste Ereignis eines Fehlers — die Grundlage für die Filter.
     *
     * Ohne Ereignis wird **nicht** gefiltert: die Meldungen sind kürzer
     * aufbewahrt als die Einträge, und ein Fehler ohne Meldung aus der Vorschau
     * zu werfen hieße, ihn wegen der Aufbewahrung zu verschweigen.
     */
    private function latestEvent(Issue $issue): ?Event
    {
        return $issue->events()->latestFirst()->first();
    }

    /**
     * @param  list<string>  $reasons
     * @return array<string, mixed>
     */
    private function row(Issue $issue, array $reasons): array
    {
        return [
            'id' => $issue->id,
            'title' => $issue->title,
            'culprit' => $issue->culprit,
            'level' => $issue->level->value,
            'levelLabel' => $issue->level->label(),
            'status' => $issue->status->value,
            'statusLabel' => $issue->status->label(),
            'timesSeen' => $issue->times_seen,
            'timesSeenLabel' => Formats::number($issue->times_seen, 0),
            'usersSeen' => $issue->users_seen,
            'lastSeenLabel' => Formats::dateTime($issue->last_seen),
            'reasons' => $reasons,
            'href' => route('issues.show', $issue),
        ];
    }
}
