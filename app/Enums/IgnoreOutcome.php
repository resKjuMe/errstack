<?php

namespace App\Enums;

use App\Support\Issues\IgnoreCondition;

/**
 * Was die Auswertung einer Stummschaltung ergeben hat
 * ({@see IgnoreCondition::evaluate()}).
 *
 * Drei Ausgänge und nicht zwei, weil ein Zeitfenster einen dritten braucht:
 * „100 Ereignisse in einer Stunde" ist nach einer erfolglosen Stunde weder
 * erreicht noch endgültig verfehlt — das Fenster beginnt neu. Ohne diesen Fall
 * bliebe nur, alle Ereignisse seit dem Stummschalten zu zählen, und dann wäre
 * die Bedingung nach drei Wochen Dauerrauschen zwangsläufig erfüllt, obwohl
 * nie etwas passiert ist, das jemanden wecken sollte.
 */
enum IgnoreOutcome
{
    /** Die Bedingung ist eingetreten: der Eintrag meldet sich wieder. */
    case Wake;

    /** Das Zeitfenster ist ohne Erreichen der Schwelle abgelaufen — es beginnt neu. */
    case Restart;

    /** Nichts zu tun: die Stummschaltung gilt weiter. */
    case Keep;
}
