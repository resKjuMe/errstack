<?php

namespace App\Support\Search;

/**
 * Die Sorten von Bausteinen, in die eine Sucheingabe zerfällt.
 *
 * Bewusst wenige: alles, was kein Klammer-, Verneinungs- oder Verknüpfungswort
 * ist, ist ein {@see self::Term} — ob daraus `schlüssel:wert` oder freier Text
 * wird, entscheidet der Zerleger selbst, weil nur er weiß, ob der Doppelpunkt in
 * Anführungszeichen stand.
 */
enum TokenType
{
    /** `schlüssel:wert` oder ein freier Begriff. */
    case Term;

    case OpenParen;

    case CloseParen;

    /** Das Ausrufezeichen vor einem Begriff oder einer Klammer. */
    case Not;

    case And;

    case Or;
}
