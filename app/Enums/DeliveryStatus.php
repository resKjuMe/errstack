<?php

namespace App\Enums;

/**
 * Stand eines einzelnen Zustellversuchs. Die Zustellung läuft in der
 * Warteschlange, deshalb ist `Pending` der Normalzustand direkt nach dem
 * Auslösen — nicht ein Fehler.
 */
enum DeliveryStatus: string
{
    /** Eingereiht, der Worker hat sie noch nicht (erfolgreich) zugestellt. */
    case Pending = 'pending';

    /** Vom Ziel angenommen. */
    case Sent = 'sent';

    /** Endgültig fehlgeschlagen — alle Versuche sind verbraucht. */
    case Failed = 'failed';

    public function label(): string
    {
        return __('enums.delivery_status.'.$this->value);
    }
}
