<?php

namespace App\Enums;

/**
 * Der Bearbeitungsstand einer Nutzer-Rückmeldung.
 *
 * Bewusst nicht derselbe Satz wie beim Fehler-Eintrag ({@see IssueStatus}): dort
 * geht es um den Zustand einer Fehlerursache, hier um den einer Zuschrift. Eine
 * Zuschrift ist gelesen oder nicht, beantwortet oder nicht — „stummgeschaltet"
 * gibt es dafür nicht, dafür „Spam", das es beim Fehler nicht gibt.
 *
 * Die Reihenfolge der Cases ist die der Auswahlliste.
 */
enum UserReportStatus: string
{
    /** Eingegangen, noch niemand hat hineingesehen. */
    case New = 'new';

    /** Jemand kümmert sich. */
    case InProgress = 'in_progress';

    /** Erledigt — beantwortet oder ins Ticketsystem übernommen. */
    case Done = 'done';

    /**
     * Werbung, Unsinn, Doppelabsendung.
     *
     * Ein eigener Zustand statt „löschen": wer Spam wegwirft, weiß hinterher
     * nicht mehr, wie viel davon kam — und genau diese Zahl entscheidet, ob die
     * Ratenbegrenzung reicht.
     */
    case Spam = 'spam';

    /** Der Zustand, in dem eine Rückmeldung entsteht. */
    public const DEFAULT = self::New;

    public function label(): string
    {
        return __('enums.user_report_status.'.$this->value);
    }

    /**
     * Steht diese Rückmeldung noch aus?
     */
    public function isOpen(): bool
    {
        return $this === self::New || $this === self::InProgress;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
