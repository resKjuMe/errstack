<?php

namespace Tests\Unit;

use App\Enums\EventLevel;
use App\Enums\IssuePriority;
use App\Support\Issues\IssueEscalation;
use App\Support\Issues\IssuePriorityScore;
use Tests\TestCase;

/**
 * Die beiden Regeln aus S11, ohne Datenbank: die Ableitung der Wichtigkeit und
 * die Feststellung einer Eskalation.
 *
 * Beide Klassen bekommen Zahlen und geben ein Urteil zurück — genau darum
 * stehen sie hier und nicht in einem Feature-Test. Die Fälle, um die es geht,
 * sind Grenzfälle („einer mehr, eine Stufe höher"), und die mit einem Testbestand
 * aus Ereignissen herzustellen wäre viel Aufwand für dieselbe Aussage.
 */
class IssuePriorityScoreTest extends TestCase
{
    public function test_a_rare_info_message_stays_at_the_bottom(): void
    {
        // Die Meldung aus der Testanleitung: ein seltener Hinweis, einmal
        // aufgetreten, niemand nennenswert betroffen.
        $score = IssuePriorityScore::derive(EventLevel::Info, events: 1, previousEvents: 0, users: 1, isNew: true);

        $this->assertSame(IssuePriority::Low, $score->priority);
    }

    public function test_a_frequent_crash_is_urgent(): void
    {
        $score = IssuePriorityScore::derive(EventLevel::Fatal, events: 400, previousEvents: 0, users: 12, isNew: true);

        $this->assertSame(IssuePriority::High, $score->priority);
    }

    public function test_a_fatal_message_alone_is_not_urgent(): void
    {
        // Die Aussage, die die Wichtigkeit vom Schweregrad trennt: ein Absturz,
        // der einmal im Quartal einen Testlauf trifft, ist nicht dringend.
        $score = IssuePriorityScore::derive(EventLevel::Fatal, events: 1, previousEvents: 1, users: 0, isNew: false);

        $this->assertSame(IssuePriority::Medium, $score->priority);
    }

    public function test_a_rising_error_moves_up_and_a_declining_one_moves_down(): void
    {
        $rising = IssuePriorityScore::derive(EventLevel::Error, events: 120, previousEvents: 20, users: 0, isNew: false);
        $declining = IssuePriorityScore::derive(EventLevel::Error, events: 10, previousEvents: 400, users: 0, isNew: false);

        $this->assertSame(IssuePriority::High, $rising->priority);
        $this->assertSame(IssuePriority::Medium, $declining->priority);

        $this->assertContains('trend_up', array_column($rising->reasons, 'key'));
        $this->assertContains('trend_down', array_column($declining->reasons, 'key'));
    }

    public function test_a_doubling_of_tiny_numbers_is_no_trend(): void
    {
        // Drei gegen eins ist eine Verdreifachung und trotzdem Zufall. Ohne die
        // Untergrenze wäre jeder ruhige Fehler einmal am Tag „stark steigend".
        $score = IssuePriorityScore::derive(EventLevel::Error, events: 3, previousEvents: 1, users: 0, isNew: false);

        $this->assertNotContains('trend_up', array_column($score->reasons, 'key'));
    }

    public function test_the_reasons_carry_the_derivation(): void
    {
        // Die Zusage der Aufgabe: die Herleitung ist nachvollziehbar. Sie steht
        // als Schlüssel und Zahl im Ergebnis und wird erst beim Lesen zu Wörtern.
        $score = IssuePriorityScore::derive(EventLevel::Error, events: 250, previousEvents: 200, users: 40, isNew: false);

        $this->assertSame(
            [
                ['key' => 'level', 'value' => 'error'],
                ['key' => 'events', 'value' => 250],
                ['key' => 'users', 'value' => 40],
            ],
            $score->reasons,
        );
        $this->assertSame(6, $score->score);
        $this->assertSame(IssuePriority::High, $score->priority);
    }

    public function test_users_without_a_user_count_do_not_push_an_issue_down(): void
    {
        // Viele SDK schicken keine Nutzerangabe. Wäre das ein Abzug, wäre jedes
        // Projekt ohne Anmeldung dauerhaft nachrangig.
        $withUsers = IssuePriorityScore::derive(EventLevel::Error, events: 150, previousEvents: 150, users: 5, isNew: false);
        $without = IssuePriorityScore::derive(EventLevel::Error, events: 150, previousEvents: 150, users: 0, isNew: false);

        $this->assertSame($withUsers->score - 1, $without->score);
        $this->assertSame(IssuePriority::Medium, $without->priority);
    }

    public function test_an_hour_far_above_the_course_escalates(): void
    {
        $escalation = IssueEscalation::check(observed: 240, expected: 4.0);

        $this->assertNotNull($escalation);
        $this->assertSame(60.0, $escalation->factor());
    }

    public function test_an_hour_within_the_usual_swing_does_not_escalate(): void
    {
        // Das Doppelte ist der Unterschied zwischen Nacht und Mittag und kein
        // Vorfall.
        $this->assertNull(IssueEscalation::check(observed: 80, expected: 40.0));
    }

    public function test_small_numbers_never_escalate(): void
    {
        // Von einem auf vier ist eine Vervierfachung — rechnerisch richtig und
        // als Meldung wertlos.
        $this->assertNull(IssueEscalation::check(observed: 4, expected: 0.2));
    }

    public function test_a_silent_issue_escalates_at_the_floor(): void
    {
        // Ohne Erwartungswert gibt es kein Vielfaches, aber sehr wohl eine
        // Aussage: zehn Meldungen in der Stunde nach Wochen der Stille.
        $escalation = IssueEscalation::check(observed: IssueEscalation::FLOOR, expected: 0.0);

        $this->assertNotNull($escalation);
        $this->assertNull($escalation->factor());
    }
}
