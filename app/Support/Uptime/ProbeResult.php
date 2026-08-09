<?php

namespace App\Support\Uptime;

use App\Enums\UptimeCheckOutcome;

/**
 * Das Ergebnis einer Prüfung: was das Ziel geantwortet hat und wie es bewertet
 * wurde.
 *
 * Ein Wert und kein Datensatz. Die Trennung ist der Grund, warum sich die
 * Bewertung ({@see UptimeProbe}) prüfen lässt, ohne dass etwas gespeichert
 * wird, und warum das Speichern ({@see UptimeRecorder}) nichts über HTTP wissen
 * muss.
 *
 * `attempts` zählt die Anläufe **einschließlich** des ersten: 1 heißt „auf
 * Anhieb", 2 heißt „die Bestätigung hat es gerettet oder bestätigt".
 */
final class ProbeResult
{
    public function __construct(
        public readonly UptimeCheckOutcome $outcome,
        public readonly ?int $httpStatus = null,
        public readonly ?int $responseTimeMs = null,
        public readonly ?string $error = null,
        public readonly int $attempts = 1,
    ) {}

    public function isFailure(): bool
    {
        return $this->outcome->isFailure();
    }

    /**
     * Dasselbe Ergebnis mit einer anderen Zahl von Anläufen.
     *
     * Die einzelne Messung weiß nicht, der wievielte Anlauf sie war — das weiß
     * nur die Schleife, die sie wiederholt.
     */
    public function withAttempts(int $attempts): self
    {
        return new self(
            outcome: $this->outcome,
            httpStatus: $this->httpStatus,
            responseTimeMs: $this->responseTimeMs,
            error: $this->error,
            attempts: max(1, $attempts),
        );
    }
}
