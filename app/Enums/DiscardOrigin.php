<?php

namespace App\Enums;

/**
 * Wer eine Meldung verworfen hat.
 *
 * Die Unterscheidung ist der eigentliche Wert der Zählung: verwirft der Server,
 * liegt es an uns (unbekannter Typ, kaputtes Element, später ein
 * überschrittenes Kontingent); verwirft das SDK, kam die Meldung nie an — dann
 * hilft kein Blick in unsere Protokolle, sondern nur der Grund, den das SDK
 * selbst nennt.
 */
enum DiscardOrigin: string
{
    /** Wir haben abgelehnt, nachdem die Meldung hier war. */
    case Server = 'server';

    /**
     * Das SDK hat verworfen und uns hinterher davon berichtet
     * ({@see IngestType::ClientReport}).
     */
    case Client = 'client';

    public function label(): string
    {
        return __('enums.discard_origin.'.$this->value);
    }
}
