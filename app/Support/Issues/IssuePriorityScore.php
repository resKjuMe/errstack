<?php

namespace App\Support\Issues;

use App\Enums\EventLevel;
use App\Enums\IssuePriority;

/**
 * Die Ableitung der Wichtigkeit aus fünf Zahlen — und die Begründung dazu.
 *
 * **Ohne Datenbank, ohne Uhr.** Die Klasse bekommt Zahlen und gibt ein Urteil
 * zurück; sie liest nichts nach. Dieselbe Entscheidung wie bei
 * {@see IgnoreCondition} und aus demselben Grund: eine Regel, die ihre Eingaben
 * selbst beschafft, ist nur mit Testbestand prüfbar — diese hier ist es mit
 * fünf Zahlen. Woher die Zahlen kommen, entscheidet
 * {@see IssuePrioritySweep}.
 *
 * **Punkte statt Schwellen-Kaskade.** Die naheliegende Alternative wäre eine
 * Kette von „wenn fatal und häufig, dann hoch" — sie wächst mit jedem Merkmal
 * um eine Verzweigung, und welcher Zweig gegriffen hat, ist hinterher nicht
 * mehr zu sagen. Hier trägt jedes Merkmal seine Punkte bei, und die Beiträge
 * sind zugleich die Herleitung: was in der Zeitleiste steht, ist genau das, was
 * gerechnet wurde ({@see self::$reasons}), und nicht eine nachträglich
 * formulierte Begründung.
 *
 * **Der Grad allein macht nichts dringend.** Eine `fatal`-Meldung, die einmal
 * im Quartal einen Testlauf trifft, bekommt drei Punkte und bleibt „mittel" —
 * genau die Aussage, die {@see IssuePriority} von {@see EventLevel} trennt.
 * Dringend wird ein Fehler erst, wenn zum Grad etwas kommt: Häufigkeit,
 * Betroffene oder ein Verlauf, der nach oben zeigt.
 */
final readonly class IssuePriorityScore
{
    /**
     * Ab wie vielen Punkten ein Eintrag dringend ist.
     *
     * Fünf, weil das genau die Fälle trifft, in denen **zwei** Merkmale
     * zusammenkommen: ein Absturz, der auch auftritt (3 + 1 + 1), oder ein
     * gewöhnlicher Fehler, der viele trifft (2 + 2 + 1). Ein einzelnes Merkmal
     * — und sei es das höchste — reicht nicht.
     */
    public const HIGH = 5;

    /**
     * Bis zu wie vielen Punkten ein Eintrag nachrangig ist.
     *
     * Eins, nicht null: „einmal neu aufgetaucht" ist der Normalfall jedes
     * frisch angelegten Eintrags, und ein Eintrag, der allein deshalb in der
     * Mitte landet, macht die Mitte zur Vorgabe für alles.
     */
    public const LOW = 1;

    /**
     * @param  list<array{key: string, value: string|int}>  $reasons  Die Beiträge, aus
     *                                                                denen die Punktzahl entstand — in der Reihenfolge, in der sie
     *                                                                gerechnet wurden.
     */
    public function __construct(
        public IssuePriority $priority,
        public int $score,
        public array $reasons,
    ) {}

    /**
     * Die Wichtigkeit aus dem, was über den Eintrag bekannt ist.
     *
     * @param  int  $events  Ereignisse in den letzten 24 Stunden
     * @param  int  $previousEvents  Ereignisse in den 24 Stunden davor — der
     *                               Vergleichswert für den Verlauf
     * @param  int  $users  Betroffene insgesamt
     * @param  bool  $isNew  Zum ersten Mal innerhalb des Beobachtungsfensters
     *                       aufgetreten
     */
    public static function derive(
        EventLevel $level,
        int $events,
        int $previousEvents,
        int $users,
        bool $isNew,
    ): self {
        $reasons = [];
        $score = 0;

        // Der Grad. Er kommt vom SDK und beschreibt die Meldung; hier ist er
        // ein Beitrag unter mehreren und nicht das Urteil.
        $levelPoints = match ($level) {
            EventLevel::Fatal => 3,
            EventLevel::Error => 2,
            EventLevel::Warning => 1,
            EventLevel::Info, EventLevel::Debug => 0,
        };

        if ($levelPoints > 0) {
            $score += $levelPoints;
            $reasons[] = ['key' => 'level', 'value' => $level->value];
        }

        // Die Häufigkeit — gemessen im laufenden Tag und nicht am Zähler seit
        // Anbeginn. Ein Fehler mit einer Million Auftreten, der seit einem
        // halben Jahr nicht mehr vorkommt, ist nicht dringend, und `times_seen`
        // könnte das nie sagen.
        $eventPoints = match (true) {
            $events >= 1000 => 3,
            $events >= 100 => 2,
            $events >= 10 => 1,
            default => 0,
        };

        if ($eventPoints > 0) {
            $score += $eventPoints;
            $reasons[] = ['key' => 'events', 'value' => $events];
        }

        // Die Betroffenen. Sie wiegen schwerer als die bloße Zahl der
        // Meldungen: zehntausend Ereignisse aus einer Schleife sind ein
        // Ärgernis, zehn betroffene Kunden sind ein Vorfall. Die Schwellen
        // liegen deshalb tiefer als bei den Ereignissen.
        //
        // Viele SDK schicken keine Nutzerangabe; dann steht hier eine Null und
        // trägt nichts bei — sie darf nur nichts **abziehen**, sonst wäre jedes
        // Projekt ohne Anmeldung dauerhaft nachrangig.
        $userPoints = match (true) {
            $users >= 100 => 3,
            $users >= 10 => 2,
            $users >= 3 => 1,
            default => 0,
        };

        if ($userPoints > 0) {
            $score += $userPoints;
            $reasons[] = ['key' => 'users', 'value' => $users];
        }

        // Neuheit. Ein Fehler, den es gestern noch nicht gab, verdient einen
        // Blick — er ist der wahrscheinlichste Kandidat für „das kam mit der
        // letzten Auslieferung". Ein Punkt und nicht mehr: neu heißt nicht
        // schlimm.
        if ($isNew) {
            $score++;
            $reasons[] = ['key' => 'new', 'value' => 1];
        }

        // Der Verlauf. Verdoppelt und mindestens zehn Ereignisse — bei drei
        // gegen eins wäre „steigend" eine Aussage über Zufall.
        if ($previousEvents > 0 && $events >= 10 && $events >= $previousEvents * 2) {
            $score++;
            $reasons[] = ['key' => 'trend_up', 'value' => $events];
        }

        // Und der Weg zurück: was abklingt, soll nicht oben stehen bleiben,
        // bloß weil es einmal oben stand. Halbiert, und nur solange überhaupt
        // etwas zu vergleichen war.
        if ($previousEvents >= 10 && $events * 2 <= $previousEvents) {
            $score--;
            $reasons[] = ['key' => 'trend_down', 'value' => $events];
        }

        return new self(self::gradeOf($score), $score, $reasons);
    }

    private static function gradeOf(int $score): IssuePriority
    {
        return match (true) {
            $score >= self::HIGH => IssuePriority::High,
            $score <= self::LOW => IssuePriority::Low,
            default => IssuePriority::Medium,
        };
    }
}
