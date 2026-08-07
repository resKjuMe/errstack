<?php

namespace App\Enums;

/**
 * Ausgang einer einzelnen Ausführung.
 *
 * Die ersten drei meldet der Job selbst; die letzten beiden stellen wir fest,
 * weil sich ein Job, der hängt oder gar nicht erst startet, nicht melden kann.
 * Genau darin liegt der Zweck der Überwachung — ein Ausfall, der sich selbst
 * meldet, wäre keiner.
 *
 * Die Werte der ersten drei sind Sentrys Werte aus dem Check-in-Rumpf.
 */
enum CronCheckInStatus: string
{
    /** Der Job hat begonnen und noch nichts weiter gemeldet. */
    case InProgress = 'in_progress';

    /** Durchgelaufen. */
    case Ok = 'ok';

    /** Der Job hat sich selbst als gescheitert gemeldet. */
    case Error = 'error';

    /** Kein Lebenszeichen innerhalb von Zeitplan plus Toleranz. */
    case Missed = 'missed';

    /** Begonnen, aber nicht innerhalb der erlaubten Laufzeit beendet. */
    case Timeout = 'timeout';

    public function label(): string
    {
        return __('enums.cron_check_in_status.'.$this->value);
    }

    /**
     * Zählt dieser Ausgang gegen die Fehlertoleranz?
     *
     * Ein laufender Job ist noch kein Ergebnis und darf deshalb weder als
     * Erfolg noch als Fehlschlag zählen.
     */
    public function isFailure(): bool
    {
        return match ($this) {
            self::Error, self::Missed, self::Timeout => true,
            default => false,
        };
    }

    /**
     * Ist die Ausführung abgeschlossen? Nur dann steht ihre Dauer fest.
     */
    public function isFinished(): bool
    {
        return $this !== self::InProgress;
    }

    /**
     * Die Werte, die ein Job selbst melden darf. `missed` und `timeout` sind
     * unsere Feststellung — nähme man sie hier an, könnte ein Job seinen
     * eigenen Ausfall melden, und das ergibt keinen Sinn.
     *
     * @return list<string>
     */
    public static function reportable(): array
    {
        return [self::InProgress->value, self::Ok->value, self::Error->value];
    }

    /**
     * Liest einen gemeldeten Wert. `null` bei allem, was ein Job nicht melden
     * darf — einschließlich unserer eigenen Feststellungen.
     */
    public static function fromReported(mixed $status): ?self
    {
        if (! is_string($status)) {
            return null;
        }

        $parsed = self::tryFrom(strtolower(trim($status)));

        return $parsed !== null && in_array($parsed->value, self::reportable(), true) ? $parsed : null;
    }
}
