<?php

namespace App\Enums;

use App\Support\Operations\BacklogWatch;

/**
 * Was aus einer Auswertung des Rückstands folgt ({@see BacklogWatch}).
 *
 * Der Unterschied zwischen „die Lage ist schlecht" und „darüber ist zu
 * sprechen" ist der Kern der ganzen Übung: schlecht ist sie schon nach der
 * ersten Minute, sprechen sollte man erst, wenn sie es bleibt — und danach
 * nicht jede Minute erneut.
 */
enum BacklogAction: string
{
    /** Nichts zu tun: alles im Rahmen, oder die Warnung ist schon heraus. */
    case None = 'none';

    /** Der Rückstand liegt lange genug über der Schwelle. */
    case Warn = 'warn';

    /** Er lag darüber und liegt es nicht mehr — die Entwarnung. */
    case Recover = 'recover';
}
