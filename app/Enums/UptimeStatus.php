<?php

namespace App\Enums;

/**
 * Zustand eines Erreichbarkeits-Monitors — die Antwort auf „ist das Ziel gerade
 * da?".
 *
 * Der Unterschied zu {@see UptimeCheckOutcome} ist derselbe wie bei den
 * Cronjobs: dort steht, wie eine einzelne Prüfung ausging, hier, woran man
 * gerade ist. Warum eine einzelne gescheiterte Prüfung noch nicht `down`
 * bedeutet, steht am Monitor (Bestätigung und Schwelle) — der Zustand folgt der
 * Entscheidung, nicht dem letzten Messwert.
 *
 * `unknown` ist deshalb kein Fehlerfall, sondern der Anfang: ein frisch
 * angelegter Monitor hat noch nichts geprüft und soll nicht so aussehen, als
 * sei alles in Ordnung.
 */
enum UptimeStatus: string
{
    /** Noch keine Prüfung gelaufen. */
    case Unknown = 'unknown';

    /** Das Ziel antwortet wie erwartet. */
    case Up = 'up';

    /**
     * Eine Prüfung ist gescheitert, die Schwelle für einen Ausfall aber noch
     * nicht erreicht.
     *
     * Der Zwischenzustand ist kein Zierrat: ohne ihn sähe ein Monitor, dessen
     * erste Bestätigungsprüfung gerade fehlgeschlagen ist, genauso aus wie
     * einer, bei dem alles läuft — und die Übersicht verschwiege damit die
     * einzige Minute, in der man noch etwas hätte tun können.
     */
    case Degraded = 'degraded';

    /** Das Ziel ist ausgefallen; ein Vorfall läuft. */
    case Down = 'down';

    /** Die Überwachung ist abgeschaltet; es wird nichts festgestellt. */
    case Disabled = 'disabled';

    public function label(): string
    {
        return __('enums.uptime_status.'.$this->value);
    }

    /**
     * Ist etwas im Argen? Steuert die Farbgebung in der Übersicht.
     */
    public function isFailing(): bool
    {
        return $this === self::Down;
    }
}
