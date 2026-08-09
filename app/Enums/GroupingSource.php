<?php

namespace App\Enums;

use App\Models\PerformanceDetection;
use App\Models\UptimeOutage;

/**
 * Woraus der Fingerabdruck einer Meldung entstanden ist.
 *
 * Die Angabe steht an jedem Ereignis und ist der erste Blick, wenn jemand
 * fragt „warum liegen die beiden in einer Gruppe?". Ohne sie wäre der
 * Fingerabdruck eine Zeichenkette ohne Herkunft — man sähe, **dass** gruppiert
 * wurde, aber nicht, **wonach**, und könnte weder die Regel korrigieren noch
 * dem SDK die Schuld geben.
 *
 * Die Reihenfolge hier ist zugleich die Rangfolge der Verfahren: was weiter
 * oben steht, gewinnt.
 */
enum GroupingSource: string
{
    /**
     * Eine projektweite Fingerprint-Regel hat gegriffen.
     *
     * Steht an erster Stelle, weil sie ausdrücklich von Hand eingerichtet wurde
     * — sie ist die Korrektur eines Groupings, das zu grob oder zu fein war,
     * und darf deshalb auch die Angabe des SDK überstimmen.
     */
    case Rule = 'rule';

    /**
     * Das SDK hat einen eigenen `fingerprint` mitgeschickt.
     */
    case Custom = 'custom';

    /**
     * Ausnahme-Typ und Stacktrace — der Regelfall bei einem Absturz.
     */
    case Stacktrace = 'stacktrace';

    /**
     * Ausnahme-Typ und -Text, weil kein brauchbarer Stacktrace dabei war.
     */
    case Exception = 'exception';

    /**
     * Der Meldungstext, bevorzugt seine Vorlage — bei einer Nachricht ohne
     * Ausnahme das Einzige, was es gibt.
     */
    case Message = 'message';

    /**
     * Titel, Fehlerstelle oder Vorgang, weil weder Ausnahme noch Meldungstext
     * etwas hergaben.
     */
    case Fallback = 'fallback';

    /**
     * Die Meldung enthielt nichts, wonach sich gruppieren ließe.
     *
     * Solche Meldungen landen gemeinsam in einer Gruppe und nicht jede in
     * ihrer eigenen: sie sind untereinander nicht unterscheidbar, und je
     * Ereignis eine Gruppe zu öffnen wäre genau die Flut, die diese Aufgabe
     * verhindern soll.
     */
    case Empty = 'empty';

    /**
     * Die Erkennung eines Leistungsmusters hat den Fingerabdruck gebildet.
     *
     * Der einzige Fall, der nicht aus einer Meldung stammt, sondern aus einem
     * bereits gespeicherten Ablauf ({@see PerformanceProblem}). Er steht
     * trotzdem hier und die Gruppe in derselben Tabelle: „was gehört zusammen"
     * ist bei einer wiederholten Abfrage dieselbe Frage wie bei einer
     * wiederholten Ausnahme, und die Antwort trägt in beiden Fällen denselben
     * Eintrag.
     *
     * Solche Gruppen haben keine Ereignisse — ihre Belege sind die Funde
     * ({@see PerformanceDetection}).
     */
    case Performance = 'performance';

    /**
     * Die Erreichbarkeits-Überwachung hat den Fingerabdruck gebildet (M2).
     *
     * Der zweite Fall, der nicht aus einer Meldung stammt, sondern aus einer
     * Feststellung von außen. Gebildet wird er über die Kennung des Monitors:
     * „die Startseite war weg" ist bei jedem Vorfall dieselbe Aussage und
     * gehört deshalb in dieselbe Gruppe — wie oft es passiert ist, sagt die
     * Häufigkeit des Eintrags.
     *
     * Solche Gruppen haben keine Ereignisse — ihre Belege sind die Ausfälle
     * ({@see UptimeOutage}).
     */
    case Uptime = 'uptime';

    public function label(): string
    {
        return __('enums.grouping_source.'.$this->value);
    }
}
