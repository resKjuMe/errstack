<?php

namespace App\Support\Integrations\GitHub;

use App\Enums\IntegrationStatus;
use RuntimeException;

/**
 * Ein Aufruf bei GitHub ist gescheitert.
 *
 * Die Klasse trägt eine Unterscheidung, an der in dieser Anbindung alles hängt:
 * **wurde der Zugang abgelehnt, oder ging der Aufruf nur schief?** Das eine
 * geht nicht von selbst vorbei und gehört in die Oberfläche (siehe
 * {@see IntegrationStatus::Disconnected}); das andere ist eine
 * Störung, die den nächsten Versuch nichts angeht.
 *
 * Beides in eine Ausnahme zu werfen und den Unterschied am Text abzulesen wäre
 * die naheliegende Abkürzung — und die Stelle, an der eine kurze Störung bei
 * GitHub dazu führt, dass eine Organisation ihre Anbindung von Hand neu
 * verbinden muss.
 */
class GitHubException extends RuntimeException
{
    /**
     * @param  bool  $accessRejected  Ob GitHub den Zugang abgelehnt hat (401/403)
     */
    public function __construct(string $message, public readonly bool $accessRejected = false)
    {
        parent::__construct($message);
    }

    /**
     * GitHub hat das Token abgelehnt: zurückgezogen, abgelaufen, oder die
     * Berechtigung für dieses Repository ist weg.
     */
    public static function accessRejected(string $message): self
    {
        return new self($message, accessRejected: true);
    }

    /**
     * Alles andere: Netzfehler, Zeitüberschreitung, ein `500` drüben, eine
     * Antwort, die kein JSON ist.
     */
    public static function failed(string $message): self
    {
        return new self($message);
    }
}
