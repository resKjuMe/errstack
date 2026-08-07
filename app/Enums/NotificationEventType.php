<?php

namespace App\Enums;

/**
 * Anlass einer Benachrichtigung — die Einheit, in der ein Nutzer entscheidet,
 * worüber er informiert werden will. Bewusst grob geschnitten: wer sich je
 * Ereignis einzeln entscheiden müsste, entscheidet gar nicht.
 *
 * Die Reihenfolge der Cases ist zugleich die Reihenfolge in der Übersicht.
 */
enum NotificationEventType: string
{
    /** Ein Alarm hat ausgelöst: etwas ist kaputt und jemand muss ran. */
    case Alert = 'alert';

    /** Ein Fehler wurde diesem Konto zugewiesen. */
    case Assignment = 'assignment';

    /** Jemand hat dieses Konto in einem Kommentar genannt. */
    case Mention = 'mention';

    /** Zustandswechsel eines Fehlers (erledigt, ignoriert, wieder offen). */
    case WorkflowChange = 'workflow_change';

    /** Eine neue Version wurde ausgeliefert. */
    case Deploy = 'deploy';

    /** Wöchentliche Zusammenfassung je Projekt. */
    case WeeklyDigest = 'weekly_digest';

    /** Das Aufnahme-Kontingent geht zur Neige. */
    case QuotaWarning = 'quota_warning';

    public function label(): string
    {
        return match ($this) {
            self::Alert => 'Alarme',
            self::Assignment => 'Zuweisungen',
            self::Mention => 'Erwähnungen',
            self::WorkflowChange => 'Workflow-Änderungen',
            self::Deploy => 'Deploys',
            self::WeeklyDigest => 'Wochenbericht',
            self::QuotaWarning => 'Kontingent-Warnungen',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Alert => 'Ein Alarm hat ausgelöst — etwas ist kaputt.',
            self::Assignment => 'Ein Fehler wurde dir zugewiesen.',
            self::Mention => 'Jemand hat dich in einem Kommentar genannt.',
            self::WorkflowChange => 'Ein Fehler wurde erledigt, ignoriert oder wieder geöffnet.',
            self::Deploy => 'Eine neue Version ist ausgeliefert worden.',
            self::WeeklyDigest => 'Die wöchentliche Zusammenfassung deiner Projekte.',
            self::QuotaWarning => 'Das Aufnahme-Kontingent geht zur Neige.',
        };
    }

    /**
     * Kritische Anlässe erreichen den Nutzer auch dann, wenn er pauschal
     * abbestellt hat oder gerade Ruhezeit ist. Sie lassen sich abschalten —
     * aber nur ausdrücklich und sichtbar, nie als Nebenwirkung.
     */
    public function isCritical(): bool
    {
        return $this === self::Alert;
    }

    /**
     * Vorgabe, solange nichts eingestellt ist. Was auffällt und selten
     * vorkommt, ist an; was ständig passiert, ist aus — sonst schaltet der
     * erste überrannte Posteingang alles ab.
     */
    public function defaultFor(NotificationTransport $transport): bool
    {
        if ($transport === NotificationTransport::InApp) {
            return true;
        }

        return match ($this) {
            self::WorkflowChange, self::Deploy => false,
            default => true,
        };
    }

    /**
     * @return list<self>
     */
    public static function critical(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $event): bool => $event->isCritical()));
    }
}
