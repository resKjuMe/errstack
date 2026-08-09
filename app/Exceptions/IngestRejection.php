<?php

namespace App\Exceptions;

use App\Support\Api\ApiErrors;
use App\Support\Ingest\IngestResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Eine Meldung wird nicht angenommen — falscher Schlüssel, zu groß, unlesbar.
 *
 * Die Ausnahme rendert sich selbst und geht damit an der einheitlichen
 * Fehlerform der öffentlichen Schnittstelle ({@see ApiErrors})
 * vorbei. Das ist Absicht: hier ruft kein eigener Client, sondern ein
 * unverändertes Sentry-SDK, und das kennt nur Sentrys Form. Ein abweichender
 * Rumpf würde in dessen Protokoll als „unerwartete Antwort" auftauchen.
 *
 * Getragen wird der Sprachschlüssel, nicht der fertige Satz: geworfen wird die
 * Ausnahme tief in der Zerlegung (App\Support\Ingest), die ohne Framework
 * auskommt und auch so geprüft wird. Übersetzt wird deshalb erst beim Rendern,
 * wo die Anfrage und mit ihr die Sprache feststeht. In Protokollen steht damit
 * der Schlüssel — als stabile Kennung des Falls sogar die bessere Auskunft.
 */
class IngestRejection extends RuntimeException
{
    /**
     * @param  array<string, string|int>  $replace
     */
    private function __construct(
        private readonly string $key,
        private readonly int $status,
        private readonly array $replace = [],
        private readonly ?int $retryAfter = null,
    ) {
        parent::__construct($key);
    }

    /**
     * Kein Schlüssel dabei, unbekannt, abgeschaltet oder zu einem anderen
     * Projekt gehörend. Alle vier Fälle geben dieselbe Auskunft: mehr zu
     * verraten hieße, beim Durchprobieren zu helfen.
     */
    public static function unauthorized(): self
    {
        return new self('ingest.unauthorized', 401);
    }

    public static function tooLarge(int $limitBytes): self
    {
        return new self('ingest.too_large', 413, ['bytes' => $limitBytes]);
    }

    /**
     * @param  string  $key  Sprachschlüssel des Grundes (siehe lang/*\/ingest.php)
     */
    public static function unreadable(string $key): self
    {
        return new self($key, 400);
    }

    /**
     * Zu viel auf einmal, oder das Monatskontingent ist aufgebraucht (O1).
     *
     * Die einzige Abweisung mit einer Wartezeit — und die ist hier der
     * eigentliche Inhalt der Antwort: `429` allein heißt „nicht jetzt", und ein
     * SDK, das nicht weiß, wie lange, versucht es gleich wieder.
     *
     * @param  string  $key  Sprachschlüssel des Grundes (siehe lang/*\/ingest.php)
     */
    public static function rateLimited(string $key, int $retryAfter): self
    {
        return new self($key, 429, ['seconds' => $retryAfter], $retryAfter);
    }

    public function render(Request $request): JsonResponse
    {
        return IngestResponse::error(__($this->key, $this->replace), $this->status, $this->retryAfter);
    }
}
