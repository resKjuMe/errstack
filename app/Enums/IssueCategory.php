<?php

namespace App\Enums;

use App\Models\Issue;

/**
 * Die Art eines Eintrags: ein Fehler oder ein Leistungsproblem.
 *
 * Beide teilen sich die Tabelle `issues` und damit die ganze Maschinerie —
 * Zustand, Priorität, Zuweisung, Alarme, Zählung. Was sie unterscheidet, ist
 * nicht die Verwaltung, sondern die Frage, die sie beantworten: „Was ist
 * kaputt?" gegen „Was ist langsam?".
 *
 * **Warum überhaupt eine Spalte und nicht zwei Tabellen:** ein zweiter
 * Eintragstyp mit eigener Tabelle hieße, jede vorhandene Funktion zweimal zu
 * bauen — die Zustandsübergänge, die Zuweisung, die Alarmregeln, die Zeitreihe.
 * Der Auftrag lautet ausdrücklich, die vorhandene Maschinerie zu nutzen; die
 * Trennung, die es braucht, ist die in der **Ansicht**, nicht die in der
 * Ablage.
 *
 * **Warum die Trennung trotzdem scharf sein muss:** ein Leistungsproblem in der
 * Fehlerliste ist ein Fehlalarm. Wer morgens die offenen Fehler durchsieht,
 * erwartet dort Ausnahmen, keine langsamen Abfragen. Deshalb filtert **jede**
 * Liste ausdrücklich nach einer Kategorie ({@see Issue::scopeOfCategory()});
 * es gibt keine Ansicht, die beide ungetrennt zeigt.
 */
enum IssueCategory: string
{
    /**
     * Ein Fehler: eine Ausnahme oder Meldung aus einem Ereignis.
     *
     * Der Vorgabewert, und zwar auch für jede Zeile, die vor dieser Spalte
     * angelegt wurde — vor den Leistungsproblemen war jeder Eintrag ein Fehler.
     */
    case Error = 'error';

    /**
     * Ein Leistungsproblem: ein Muster, das die Erkennung in einem
     * gespeicherten Ablauf gefunden hat ({@see PerformanceProblem}).
     */
    case Performance = 'performance';

    /**
     * Die Kategorie, in der ein Eintrag ohne ausdrückliche Angabe entsteht.
     */
    public const DEFAULT = self::Error;

    public function label(): string
    {
        return __('enums.issue_category.'.$this->value);
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
