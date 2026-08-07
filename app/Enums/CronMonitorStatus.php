<?php

namespace App\Enums;

/**
 * Zustand eines überwachten Jobs — das Ergebnis seiner letzten Ausführung, nicht
 * das einer einzelnen.
 *
 * Der Unterschied zu {@see CronCheckInStatus} ist der Zeitbezug: dort steht, wie
 * ein bestimmter Lauf ausging, hier, woran man gerade ist. `unknown` ist
 * deshalb kein Fehlerfall, sondern der Anfang: ein frisch angelegter Monitor
 * hat noch keine Ausführung gesehen und soll nicht so aussehen, als sei alles
 * in Ordnung.
 */
enum CronMonitorStatus: string
{
    /** Noch keine Ausführung gesehen. */
    case Unknown = 'unknown';

    /** Die letzte Ausführung lief durch. */
    case Ok = 'ok';

    /** Eine Ausführung läuft gerade. */
    case Running = 'running';

    /** Eine Ausführung ist ausgeblieben. */
    case Missed = 'missed';

    /** Eine Ausführung lief zu lange. */
    case Timeout = 'timeout';

    /** Die letzte Ausführung hat sich als gescheitert gemeldet. */
    case Error = 'error';

    /** Die Überwachung ist abgeschaltet; es wird nichts festgestellt. */
    case Disabled = 'disabled';

    public function label(): string
    {
        return __('enums.cron_monitor_status.'.$this->value);
    }

    /**
     * Ist etwas im Argen? Steuert die Farbgebung in der Übersicht.
     */
    public function isFailing(): bool
    {
        return match ($this) {
            self::Missed, self::Timeout, self::Error => true,
            default => false,
        };
    }

    /**
     * Der Zustand, der aus dem Ausgang einer Ausführung folgt.
     */
    public static function fromCheckIn(CronCheckInStatus $status): self
    {
        return match ($status) {
            CronCheckInStatus::InProgress => self::Running,
            CronCheckInStatus::Ok => self::Ok,
            CronCheckInStatus::Error => self::Error,
            CronCheckInStatus::Missed => self::Missed,
            CronCheckInStatus::Timeout => self::Timeout,
        };
    }
}
