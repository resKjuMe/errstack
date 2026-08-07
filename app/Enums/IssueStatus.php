<?php

namespace App\Enums;

/**
 * Der Zustand eines Fehler-Eintrags: offen, erledigt oder stummgeschaltet.
 *
 * Drei Fälle und nicht zwei, weil „erledigt" und „will ich nicht sehen"
 * verschiedene Aussagen sind. Wer einen Fehler auflöst, sagt: das ist behoben,
 * und wenn er wiederkommt, will ich es wissen. Wer ihn stummschaltet, sagt: der
 * bleibt, aber er soll mich nicht mehr wecken. Mit nur einem Zustand für beides
 * würde entweder die Liste mit Bekanntem zulaufen oder ein behobener Fehler bei
 * seiner Rückkehr unbemerkt bleiben.
 *
 * Ob ein aufgelöster Eintrag bei erneutem Auftreten von selbst wieder aufgeht,
 * entscheidet nicht dieser Zustand, sondern {@see ResolutionBehavior} am
 * Projekt — hier steht nur, woran der Eintrag gerade ist.
 */
enum IssueStatus: string
{
    /**
     * Offen — der Vorgabewert. Jeder Eintrag beginnt hier, auch der, den
     * jemand gestern erledigt hatte und der heute wiederkommt.
     */
    case Unresolved = 'unresolved';

    /**
     * Von Hand oder nach Ablauf der Frist aufgelöst.
     */
    case Resolved = 'resolved';

    /**
     * Stummgeschaltet: der Fehler tritt weiter auf und wird weiter gezählt,
     * meldet sich aber nicht mehr.
     *
     * Die Zähler laufen dabei ausdrücklich weiter. Ein stummgeschalteter
     * Eintrag, der nicht mehr zählt, wäre nach vier Wochen nicht mehr zu
     * beurteilen — und die Frage, ob das Stummschalten noch berechtigt ist,
     * stellt sich genau dann.
     */
    case Ignored = 'ignored';

    /**
     * Der Zustand, in dem ein Eintrag entsteht.
     */
    public const DEFAULT = self::Unresolved;

    public function label(): string
    {
        return __('enums.issue_status.'.$this->value);
    }

    /**
     * Zählt dieser Zustand in der Fehlerliste als „noch zu tun"?
     */
    public function isOpen(): bool
    {
        return $this === self::Unresolved;
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
