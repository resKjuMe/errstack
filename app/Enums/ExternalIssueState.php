<?php

namespace App\Enums;

/**
 * Der Zustand eines verknüpften Tickets beim Anbieter.
 *
 * Nur zwei Fälle, weil GitHub nur zwei kennt (`open`, `closed`). Der Grund für
 * die Aufzählung ist nicht die Anzeige, sondern der Abgleich: „geschlossen"
 * ist die Bedingung, unter der der Fehler hier als erledigt gilt — und diese
 * Bedingung soll an einem Wert hängen, den der Code kennt, nicht an einer
 * Zeichenkette aus einer fremden Nutzlast.
 */
enum ExternalIssueState: string
{
    case Open = 'open';

    case Closed = 'closed';

    public function label(): string
    {
        return __('enums.external_issue_state.'.$this->value);
    }

    /**
     * Die Angabe aus einer Nutzlast, nachsichtig gelesen.
     *
     * Ein unbekannter Wert wird zu {@see Open} und nicht zu einem Fehler: der
     * Aufruf kommt aus einem Webhook, und ein Zustand, den wir nicht kennen,
     * ist mit Sicherheit keiner, unter dem ein Fehler als erledigt gelten
     * darf. Die vorsichtige Annahme ist hier die offene.
     */
    public static function fromInput(?string $value): self
    {
        return self::tryFrom(strtolower(trim((string) $value))) ?? self::Open;
    }
}
