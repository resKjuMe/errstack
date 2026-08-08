<?php

namespace App\Enums;

use App\Models\Release;

/**
 * Woraufhin ein Fehler als erledigt gilt.
 *
 * Drei Fälle, weil „erledigt" drei verschiedene Aussagen sein kann und nur die
 * erste ohne Bedingung auskommt:
 *
 *   sofort           — der Fehler ist weg, Punkt. Kommt er wieder, ist das eine
 *                      Rückkehr (S8) und keine Frage dieses Enums.
 *   in dieser Version — „behoben in dem, was gerade draußen ist". Trifft der
 *                      Fehler danach aus **derselben oder einer älteren**
 *                      Version ein, ist das kein Widerspruch: die Meldung kommt
 *                      von einem Stand ohne den Fix.
 *   im nächsten Release — „der Fix ist geschrieben, aber noch nicht ausgeliefert".
 *                      Bis zur nächsten Auslieferung bleibt der Eintrag außer
 *                      Sicht, und ab ihr gilt dasselbe wie oben.
 *
 * Die Unterscheidung steht hier und nicht als Häkchen im Formular, weil an ihr
 * die Auswertung hängt: {@see Release} ist der Bezugspunkt, und ohne ihn wäre
 * „erledigt" nicht überprüfbar, sondern nur behauptet.
 */
enum IssueResolveMode: string
{
    case Now = 'now';

    case CurrentRelease = 'current_release';

    case NextRelease = 'next_release';

    public function label(): string
    {
        return __('enums.issue_resolve_mode.'.$this->value);
    }

    /**
     * Nennt dieser Fall die Version, in der der Fehler behoben **ist**?
     *
     * Nur „in dieser Version". „Im nächsten Release" merkt sich dieselbe
     * Version, aber als **Bezugspunkt** und nicht als Ziel: behoben ist der
     * Fehler dort erst in dem, was danach kommt
     * ({@see App\Support\Issues\IssueActions::resolve()}). Der Unterschied ist
     * der zwischen „behoben in 1.4.2" und „behoben nach 1.4.2" — für die
     * Rückfallerkennung dieselbe Prüfung, für den Satz in der Oberfläche zwei
     * verschiedene Aussagen.
     */
    public function namesRelease(): bool
    {
        return $this === self::CurrentRelease;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $mode): array => ['value' => $mode->value, 'label' => $mode->label()],
            self::cases(),
        );
    }
}
