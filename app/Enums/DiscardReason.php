<?php

namespace App\Enums;

use App\Models\IngestDiscard;

/**
 * Warum die Aufnahme ein Element verworfen hat.
 *
 * Nur die Gründe der **eigenen** Seite stehen hier. Was ein SDK verwirft,
 * begründet es mit seinen eigenen Bezeichnungen (`queue_overflow`,
 * `ratelimit_backoff`, `before_send` …), und die Liste wächst mit jeder
 * SDK-Fassung — die wird deshalb als Zeichenkette übernommen und nicht hier
 * nachgepflegt. Siehe {@see IngestDiscard}.
 */
enum DiscardReason: string
{
    /**
     * Ein Element-Typ, den wir nicht kennen. Sentry erweitert die Liste
     * laufend; ein unbekannter Typ ist deshalb ein normaler Vorgang und kein
     * Fehler.
     */
    case UnknownType = 'unknown_type';

    /** Kopf oder Nutzdaten des Elements ließen sich nicht lesen. */
    case Unreadable = 'unreadable';

    /** Das Element allein überschreitet die erlaubte Größe. */
    case TooLarge = 'too_large';

    /** Der Envelope enthielt mehr Elemente, als wir annehmen. */
    case TooManyItems = 'too_many_items';

    /**
     * Dieselbe Meldung war schon ausgewertet. Anders als die Gründe darüber
     * fällt dieser nicht bei der Annahme an, sondern erst in der Verarbeitung:
     * eine wiederholte Zustellung wird angenommen wie jede andere, sonst müsste
     * der Endpunkt vor seiner Antwort nachsehen.
     */
    case Duplicate = 'duplicate';

    public function label(): string
    {
        return __('enums.discard_reason.'.$this->value);
    }
}
