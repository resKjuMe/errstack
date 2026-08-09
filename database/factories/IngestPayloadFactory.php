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
     * Ein Binärelement: Anhang oder Aufzeichnung.
     *
     * Nötig, weil {@see body()} JSON schreibt und ein Screenshot keines ist. Der
     * Weg über {@see IngestPayload::accept()} wäre die Alternative und die
     * umständlichere: er verlangt einen Projektschlüssel und legt damit auch das
     * Projekt fest — hier soll ein Test einen Anhang an eine **bestimmte**
     * Ereignisnummer hängen können, ohne den halben Aufnahmeweg nachzubauen.
     *
     * Verpackt wird wie in der Aufnahme am Inhalt und nicht am Typ, und
     * `size_bytes` ist die Größe der Nutzdaten und nicht die der Spalte.
     *
     * @param  array<string, mixed>  $itemHeaders  Kopf des Envelope-Elements
     *                                             (`filename`, `content_type`).
     */
    public function bytes(
        string $payload,
        IngestType $type = IngestType::Attachment,
        array $itemHeaders = [],
        ?string $eventId = null,
    ): static {
        return $this->state(function () use ($payload, $type, $itemHeaders, $eventId): array {
            $isText = $payload === ''
                || (mb_check_encoding($payload, 'UTF-8') && ! str_contains($payload, "\0"));

            return [
                'event_id' => IngestPayload::normalizeEventId($eventId) ?? IngestPayload::freshEventId(),
                'type' => $type,
                'item_headers' => $itemHeaders,
                'payload' => $isText ? $payload : base64_encode($payload),
                'payload_encoding' => $isText ? null : IngestPayload::ENCODING_BASE64,
                'size_bytes' => strlen($payload),
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
