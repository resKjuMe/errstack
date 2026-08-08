<?php

namespace Tests\Unit;

use App\Enums\IgnoreOutcome;
use App\Enums\IssueIgnoreMode;
use App\Support\Issues\IgnoreCondition;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * Die Bedingung einer Stummschaltung für sich, ohne Datenbank.
 *
 * Sie läuft bei **jedem** eingehenden Ereignis eines stummgeschalteten Eintrags;
 * ein Fehler darin heißt entweder „der Fehler meldet sich nie wieder" oder „er
 * meldet sich sofort wieder" — beides fällt im Betrieb erst auf, wenn es zu spät
 * ist. Geprüft wird deshalb der Rand jeder Schwelle und nicht ihre Mitte.
 */
class IgnoreConditionTest extends TestCase
{
    private CarbonImmutable $since;

    protected function setUp(): void
    {
        parent::setUp();

        $this->since = CarbonImmutable::parse('2026-03-10 12:00:00', 'UTC');
    }

    public function test_permanent_never_wakes(): void
    {
        $condition = $this->condition(IssueIgnoreMode::Forever);

        $this->assertTrue($condition->isPermanent());
        $this->assertSame(
            IgnoreOutcome::Keep,
            $condition->evaluate(timesSeen: 1_000_000, usersSeen: 5_000, now: $this->since->addYear()),
        );
    }

    /**
     * „Bis er wieder auftritt" ist eine Schwelle von eins — und wird gegen den
     * Stand beim Stummschalten gemessen. Ein Eintrag mit 40.000 Auftreten wäre
     * sonst in derselben Sekunde wieder wach, in der ihn jemand stumm geschaltet
     * hat.
     */
    public function test_until_recurrence_wakes_on_the_next_event(): void
    {
        $condition = $this->condition(IssueIgnoreMode::UntilRecurrence, timesSeenAtStart: 40_000);

        $this->assertSame(
            IgnoreOutcome::Keep,
            $condition->evaluate(40_000, 0, $this->since->addMinute()),
        );

        $this->assertSame(
            IgnoreOutcome::Wake,
            $condition->evaluate(40_001, 0, $this->since->addMinute()),
        );
    }

    public function test_count_threshold_wakes_exactly_at_the_threshold(): void
    {
        $condition = $this->condition(IssueIgnoreMode::UntilCount, count: 100, timesSeenAtStart: 5);

        $this->assertSame(IgnoreOutcome::Keep, $condition->evaluate(104, 0, $this->since));
        $this->assertSame(IgnoreOutcome::Wake, $condition->evaluate(105, 0, $this->since));
    }

    /**
     * Der Fall aus der Aufgabenstellung: „bis 100 weitere Ereignisse" und fünf
     * davon treffen ein — der Eintrag bleibt still.
     */
    public function test_five_of_a_hundred_stay_quiet(): void
    {
        $condition = $this->condition(IssueIgnoreMode::UntilCount, count: 100);

        $this->assertSame(IgnoreOutcome::Keep, $condition->evaluate(5, 0, $this->since));
    }

    /**
     * Ein Fenster wird zurückgesetzt und nicht summiert: „100 in einer Stunde"
     * fragt nach einer Welle. Zählte man ab dem Stummschalten weiter, wäre die
     * Schwelle nach genügend Zeit auch bei ruhigem Dauerrauschen erreicht.
     */
    public function test_window_restarts_when_the_threshold_is_missed(): void
    {
        $condition = $this->condition(IssueIgnoreMode::UntilCount, count: 100, window: 60);

        $this->assertSame(
            IgnoreOutcome::Keep,
            $condition->evaluate(40, 0, $this->since->addMinutes(30)),
        );

        $this->assertSame(
            IgnoreOutcome::Restart,
            $condition->evaluate(40, 0, $this->since->addMinutes(60)),
        );
    }

    /**
     * Die Schwelle wird **vor** dem Ablauf des Fensters geprüft: das hundertste
     * Ereignis kann in derselben Sekunde eintreffen, in der die Stunde endet —
     * und dann ist die Welle da gewesen, nicht verpasst.
     */
    public function test_threshold_beats_the_expiring_window(): void
    {
        $condition = $this->condition(IssueIgnoreMode::UntilCount, count: 100, window: 60);

        $this->assertSame(
            IgnoreOutcome::Wake,
            $condition->evaluate(100, 0, $this->since->addMinutes(60)),
        );
    }

    public function test_user_threshold_counts_affected_users(): void
    {
        $condition = $this->condition(IssueIgnoreMode::UntilUsers, count: 10, usersSeenAtStart: 3);

        // Ereignisse allein wecken nicht: zehntausend aus einem Testlauf sind
        // kein Grund, zehn betroffene Kunden schon.
        $this->assertSame(IgnoreOutcome::Keep, $condition->evaluate(10_000, 12, $this->since));
        $this->assertSame(IgnoreOutcome::Wake, $condition->evaluate(10_000, 13, $this->since));
    }

    /**
     * Zu „bis X Nutzer" gehört kein Zeitfenster — die Betroffenen werden am
     * Eintrag insgesamt gezählt.
     */
    public function test_user_threshold_has_no_window(): void
    {
        $this->assertFalse(IssueIgnoreMode::UntilUsers->allowsWindow());

        $columns = IgnoreCondition::columnsFor(IssueIgnoreMode::UntilUsers, 10, 60);

        $this->assertSame(['count' => null, 'window' => null, 'users' => 10], $columns);
    }

    private function condition(
        IssueIgnoreMode $mode,
        ?int $count = null,
        ?int $window = null,
        int $timesSeenAtStart = 0,
        int $usersSeenAtStart = 0,
    ): IgnoreCondition {
        $columns = IgnoreCondition::columnsFor($mode, $count, $window);

        return new IgnoreCondition(
            count: $columns['count'],
            windowMinutes: $columns['window'],
            users: $columns['users'],
            since: $this->since,
            timesSeenAtStart: $timesSeenAtStart,
            usersSeenAtStart: $usersSeenAtStart,
        );
    }
}
