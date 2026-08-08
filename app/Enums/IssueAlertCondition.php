<?php

namespace App\Enums;

/**
 * Der Anlass einer Alarm-Regel: **wann** überhaupt hingesehen wird.
 *
 * Die Trennung von Bedingung und Filter ({@see IssueAlertFilter}) ist keine
 * Ordnungsfrage, sondern der Grund, warum die Auswertung im Aufnahmeweg
 * bezahlbar bleibt. Eine Bedingung sagt „hier ist etwas passiert" und ist an
 * einem Ereignis abzulesen; ein Filter sagt „und zwar an einem Fehler dieser
 * Art" und darf dafür nachschlagen. Ohne diese Reihenfolge würde jede Regel bei
 * jedem Ereignis den ganzen Eintrag laden.
 */
enum IssueAlertCondition: string
{
    /** Ein Fehler tritt zum ersten Mal auf. */
    case NewIssue = 'new_issue';

    /**
     * Ein erledigter Fehler tritt wieder auf.
     *
     * Erkannt wird das hier nur **für die Meldung**: der Eintrag bleibt
     * erledigt, weil das Wiederaufmachen zur Rückfallerkennung (S8) gehört.
     * Damit derselbe Rückfall nicht in jedem Zeitfenster erneut gemeldet wird,
     * merkt sich der Regel-Zustand den Auflösungszeitpunkt, zu dem gemeldet
     * wurde (App\Models\IssueAlertState).
     */
    case Regression = 'regression';

    /**
     * Ein stummgeschalteter Fehler reißt seine Bedingung und wacht auf.
     *
     * Das Aufwachen selbst entscheidet die Stummschaltung (S6); diese Bedingung
     * hört nur mit. Sie ist deshalb die einzige, die nicht aus dem Eintrag
     * abzulesen ist, sondern aus dem, was der Verarbeitungsschritt davor getan
     * hat.
     */
    case Escalation = 'escalation';

    /** Ein Fehler tritt öfter als X-mal in Y Minuten auf. */
    case Frequency = 'frequency';

    /** Ein Fehler betrifft mehr als X Nutzer in Y Minuten. */
    case UserFrequency = 'user_frequency';

    /** Ein Fehler tritt um X % häufiger auf als in derselben Spanne der Vorwoche. */
    case PercentChange = 'percent_change';

    /**
     * Braucht diese Bedingung eine Zahl (`value`)?
     */
    public function hasValue(): bool
    {
        return match ($this) {
            self::NewIssue, self::Regression, self::Escalation => false,
            default => true,
        };
    }

    /**
     * Braucht diese Bedingung ein Zeitfenster — und in welcher Einheit?
     *
     * Minuten für das, was am Ereignisstrom gezählt wird, Stunden für den
     * Vergleich mit der Vorwoche: der geht über die vorberechneten Stunden- und
     * Tageszähler (App\Models\IssueCount), und eine Angabe in Minuten wäre dort
     * eine Genauigkeit, die es nicht gibt.
     */
    public function windowUnit(): ?IssueAlertWindow
    {
        return match ($this) {
            self::Frequency, self::UserFrequency => IssueAlertWindow::Minutes,
            self::PercentChange => IssueAlertWindow::Hours,
            default => null,
        };
    }

    public function label(): string
    {
        return __('enums.issue_alert_condition.'.$this->value);
    }

    /**
     * @return list<array{value: string, label: string, hasValue: bool, window: string|null}>
     */
    public static function options(): array
    {
        return array_map(static fn (self $condition): array => [
            'value' => $condition->value,
            'label' => $condition->label(),
            'hasValue' => $condition->hasValue(),
            'window' => $condition->windowUnit()?->value,
        ], self::cases());
    }
}
