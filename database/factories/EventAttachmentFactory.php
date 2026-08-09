<?php

namespace Database\Factories;

use App\Enums\AttachmentKind;
use App\Models\Event;
use App\Models\EventAttachment;
use App\Models\IngestPayload;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<EventAttachment>
 */
class EventAttachmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $content = 'Anhang '.fake()->sentence();
        $checksum = sha1($content);

        return [
            'project_id' => Project::factory(),
            'ingest_payload_id' => null,
            // Ohne Bezug zu einer abgelegten Meldung: das ist der Fall, der ohne
            // weiteres Zutun steht — ein Anhang trifft regelmäßig vor seiner
            // Meldung ein. Wer einen Bezug braucht, nimmt `forEvent()`.
            'event_reference' => IngestPayload::freshEventId(),
            'name' => 'protokoll.txt',
            'content_type' => 'text/plain',
            'kind' => AttachmentKind::Text,
            'size' => strlen($content),
            'checksum' => $checksum,
            'path' => 'event-attachments/1/'.substr($checksum, 0, 2).'/'.$checksum,
            'received_at' => Carbon::now()->subMinutes(fake()->numberBetween(1, 600)),
        ];
    }

    /**
     * Ein Anhang an einer bestimmten Meldung.
     */
    public function forEvent(Event $event): self
    {
        return $this->state(fn (): array => [
            'project_id' => $event->project_id,
            'event_reference' => $event->event_id,
        ]);
    }

    /**
     * Ein Bild — der Fall mit Vorschau.
     */
    public function image(string $name = 'screenshot.png'): self
    {
        return $this->state(fn (): array => [
            'name' => $name,
            'content_type' => 'image/png',
            'kind' => AttachmentKind::Image,
        ]);
    }

    /**
     * Ein Anhang, der nur heruntergeladen werden darf.
     */
    public function binary(string $name = 'absturz.dmp'): self
    {
        return $this->state(fn (): array => [
            'name' => $name,
            'content_type' => 'application/octet-stream',
            'kind' => AttachmentKind::Binary,
        ]);
    }

    /**
     * Vor `$days` Tagen eingetroffen — für das Aufräumen.
     */
    public function receivedDaysAgo(int $days): self
    {
        return $this->state(fn (): array => [
            'received_at' => Carbon::now()->subDays($days),
        ]);
    }
}
