<?php

namespace App\Enums;

/**
 * Art einer angenommenen Meldung. Die Werte sind die Element-Typen der
 * Sentry-Envelope-Spezifikation — auch für den klassischen Store-Endpunkt, der
 * ausschließlich Fehler (`event`) liefert. Damit ist die Eingangsablage von
 * Anfang an dieselbe für alle Wege; der Envelope-Endpunkt ergänzt hier nur
 * weitere Fälle (Transaktionen, Sitzungen, Anhänge …), ohne die Tabelle oder
 * die Verarbeitung umzubauen.
 */
enum IngestType: string
{
    /** Eine Fehlermeldung — was über `/store/` hereinkommt. */
    case Event = 'event';

    public function label(): string
    {
        return match ($this) {
            self::Event => 'Fehlermeldung',
        };
    }
}
