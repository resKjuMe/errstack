<?php

namespace App\Support\Integrations\Tickets;

use App\Enums\IntegrationStatus;
use RuntimeException;

/**
 * Ein Aufruf beim Ticket-System ist gescheitert (X4).
 *
 * Dieselbe Unterscheidung wie bei GitHub, und sie ist hier nicht weniger wichtig
 * als dort: **wurde der Zugang abgelehnt, oder ging der Aufruf nur schief?** Das
 * eine geht nicht von selbst vorbei und gehört in die Oberfläche (siehe
 * {@see IntegrationStatus::Disconnected}); das andere ist eine Störung, die den
 * nächsten Versuch nichts angeht.
 *
 * Eine eigene Klasse und nicht die von GitHub, obwohl beide dasselbe tragen: die
 * Ausnahme sagt mit ihrem Namen, wer geantwortet hat, und ein
 * `GitHubException: Der Vorgang OPS-42 existiert nicht` wäre die Art Meldung,
 * die eine halbe Stunde Suche kostet. Was beide gemeinsam haben, ist die
 * Unterscheidung — und die ist nicht viel Code, sondern eine Entscheidung.
 *
 * **Die Meldung ist zum Anzeigen gedacht.** Sie trägt, was der Anbieter gesagt
 * hat, und ist bewusst nicht durch einen freundlicheren eigenen Satz ersetzt:
 * „Field 'priority' cannot be set" ist die Antwort auf „warum entsteht kein
 * Ticket", und ein „Das Ticket konnte nicht angelegt werden" verbirgt sie nur
 * (Abnahmekriterium „Fehler der Fremdsysteme werden verständlich gemeldet, nicht
 * verschluckt").
 */
class TicketException extends RuntimeException
{
    /**
     * @param  bool  $accessRejected  Ob der Anbieter den Zugang abgelehnt hat (401/403)
     */
    public function __construct(string $message, public readonly bool $accessRejected = false)
    {
        parent::__construct($message);
    }

    /**
     * Der Zugang wurde abgelehnt: Token zurückgezogen, abgelaufen, oder die
     * Berechtigung für dieses Projekt ist weg.
     */
    public static function accessRejected(string $message): self
    {
        return new self($message, accessRejected: true);
    }

    /**
     * Alles andere: Netzfehler, Zeitüberschreitung, ein `500` drüben, eine
     * Antwort, die kein JSON ist — und die Fachfehler des Anbieters („es gibt
     * kein OPS-4711").
     */
    public static function failed(string $message): self
    {
        return new self($message);
    }
}
