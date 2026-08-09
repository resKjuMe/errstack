<?php

namespace Database\Factories;

use App\Enums\IngestType;
use App\Enums\ProcessingState;
use App\Models\IngestPayload;
use App\Models\Project;
use App\Models\ProjectKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IngestPayload>
 */
class IngestPayloadFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $eventId = IngestPayload::freshEventId();

        $payload = (string) json_encode([
            'event_id' => $eventId,
            'timestamp' => now()->toIso8601String(),
            'platform' => 'php',
            'message' => fake()->sentence(),
        ]);

        return [
            'project_id' => Project::factory(),
            'project_key_id' => null,
            'event_id' => $eventId,
            'type' => IngestType::Event,
            'sdk' => 'sentry.php/4.0.0',
            'payload' => $payload,
            'size_bytes' => strlen($payload),

            // Eine erzeugte Meldung ist eine angenommene: sie wartet auf ihre
            // Auswertung, wie jede über den Endpunkt.
            'processing_state' => ProcessingState::Pending,
            'attempts' => 0,
        ];
    }

    /**
     * Meldung mit einem selbst gewählten Rumpf.
     *
     * Nötig für alles, was die Verarbeitung prüft: dort ist der Rumpf nicht
     * Beiwerk, sondern der Gegenstand. Die Nummer wird aus ihm übernommen, damit
     * Spalte und Rumpf dieselbe nennen — auseinanderlaufen würden sie sonst
     * genau dort, wo die Doppelerkennung hinsieht.
     *
     * @param  array<string, mixed>  $body
     */
    public function body(array $body, IngestType $type = IngestType::Event): static
    {
        return $this->state(function () use ($body, $type): array {
            $payload = (string) json_encode($body);

            return [
                'event_id' => IngestPayload::normalizeEventId($body['event_id'] ?? null)
                    ?? IngestPayload::freshEventId(),
                'type' => $type,
                'payload' => $payload,
                'size_bytes' => strlen($payload),
            ];
        });
    }

    /**
     * Meldung mit beliebigen Bytes statt eines Feld-Baums.
     *
     * Für die Element-Typen, deren Rumpf kein JSON ist: ein Anhang, eine
     * Sitzungs-Aufzeichnung. Die Verpackung übernimmt {@see IngestPayload::accept()}
     * im Betrieb; hier wird sie nachgebaut, weil eine Factory keinen Endpunkt
     * durchläuft — ohne sie stünden Nullbytes und ungültiges UTF-8 roh in der
     * Textspalte, und was danach herauskäme, wäre nicht mehr das, was
     * hineinging.
     *
     * Der Name ist `bytes()` und nicht `raw()`: `raw()` gehört der Factory-Basis
     * und bedeutet dort etwas anderes (die Attribute ohne Modell).
     *
     * @param  array<string, mixed>|null  $itemHeaders  Kopf des Envelope-Elements.
     */
    public function bytes(string $bytes, IngestType $type, ?string $eventId = null, ?array $itemHeaders = null): static
    {
        return $this->state(function () use ($bytes, $type, $eventId, $itemHeaders): array {
            $isText = $bytes === ''
                || (mb_check_encoding($bytes, 'UTF-8') && ! str_contains($bytes, "\0"));

            return [
                'event_id' => IngestPayload::normalizeEventId($eventId) ?? IngestPayload::freshEventId(),
                'type' => $type,
                'item_headers' => $itemHeaders,
                'payload' => $isText ? $bytes : base64_encode($bytes),
                'payload_encoding' => $isText ? null : IngestPayload::ENCODING_BASE64,
                'size_bytes' => strlen($bytes),
            ];
        });
    }

    /**
     * Meldung, die über einen bestimmten Schlüssel hereinkam — wie im Betrieb,
     * wo jede Meldung ihre DSN mitbringt.
     */
    public function viaKey(ProjectKey $key): static
    {
        return $this->state(fn (): array => [
            'project_id' => $key->project_id,
            'project_key_id' => $key->id,
        ]);
    }
}
