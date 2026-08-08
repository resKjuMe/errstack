<?php

namespace App\Enums;

use App\Support\Issues\IgnoreCondition;

/**
 * Woran das Stummschalten endet.
 *
 * Vier Fälle, und sie unterscheiden sich nicht im Grad, sondern in der Frage,
 * die sie beantworten:
 *
 *   dauerhaft          — „den will ich nie wieder sehen." Endet nur von Hand.
 *   bis es wieder auftritt — „gerade nicht, aber sag Bescheid, wenn es weitergeht."
 *                        Das nächste Ereignis holt den Eintrag zurück.
 *   bis X in Y         — „ein paar Nachzügler sind normal, eine Welle nicht."
 *                        Erst wenn die Schwelle innerhalb des Fensters fällt,
 *                        meldet sich der Eintrag.
 *   bis X Nutzer       — dasselbe, nur an Betroffenen gemessen. Zehntausend
 *                        Ereignisse aus einem Testlauf sind kein Grund,
 *                        zehn betroffene Kunden schon.
 *
 * Ausgewertet wird das nicht hier, sondern in {@see IgnoreCondition} — dieses
 * Enum sagt nur, welche Angaben zu einem Fall gehören, damit die Prüfung des
 * Formulars nicht raten muss.
 */
enum IssueIgnoreMode: string
{
    case Forever = 'forever';

    case UntilRecurrence = 'until_recurrence';

    case UntilCount = 'until_count';

    case UntilUsers = 'until_users';

    public function label(): string
    {
        return __('enums.issue_ignore_mode.'.$this->value);
    }

    /**
     * Braucht dieser Fall eine Schwelle (`count`)?
     */
    public function needsCount(): bool
    {
        return $this === self::UntilCount || $this === self::UntilUsers;
    }

    /**
     * Darf zu diesem Fall ein Zeitfenster (`window`) gehören?
     *
     * Nur bei der Ereignis-Schwelle: „100 Ereignisse in einer Stunde" ist die
     * Aussage, die eine Welle von einem Dauerrauschen trennt. Bei Betroffenen
     * ist das Fenster bewusst nicht vorgesehen — die Zahl der Betroffenen wird
     * am Eintrag insgesamt gezählt und nicht je Zeitfenster; ein Fenster dort
     * anzubieten hieße, eine Genauigkeit zu versprechen, die die Zähler nicht
     * hergeben.
     */
    public function allowsWindow(): bool
    {
        return $this === self::UntilCount;
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
