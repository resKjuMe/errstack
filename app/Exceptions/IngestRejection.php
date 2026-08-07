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
 */
class IngestRejection extends RuntimeException
{
    private function __construct(
        string $message,
        private readonly int $status,
    ) {
        parent::__construct($message);
    }

    /**
     * Kein Schlüssel dabei, unbekannt, abgeschaltet oder zu einem anderen
     * Projekt gehörend. Alle vier Fälle geben dieselbe Auskunft: mehr zu
     * verraten hieße, beim Durchprobieren zu helfen.
     */
    public static function unauthorized(): self
    {
        return new self('Der Client-Schlüssel ist unbekannt oder gehört nicht zu diesem Projekt.', 401);
    }

    public static function tooLarge(int $limitBytes): self
    {
        return new self("Die Meldung ist zu groß — erlaubt sind {$limitBytes} Byte.", 413);
    }

    public static function unreadable(string $reason): self
    {
        return new self($reason, 400);
    }

    public function render(Request $request): JsonResponse
    {
        return IngestResponse::error($this->getMessage(), $this->status);
    }
}
