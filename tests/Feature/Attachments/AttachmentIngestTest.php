<?php

namespace Tests\Feature\Attachments;

use App\Enums\AttachmentKind;
use App\Enums\DiscardReason;
use App\Enums\IngestType;
use App\Enums\ProcessingState;
use App\Jobs\ProcessIngestPayload;
use App\Models\EventAttachment;
use App\Models\IngestDiscard;
use App\Models\IngestPayload;
use App\Models\Project;
use App\Models\ProjectKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Die Aufnahme von Anhängen, vom angenommenen Envelope-Element bis zur
 * abgelegten Datei.
 *
 * Geprüft wird, was die Anzeige voraussetzt: dass ein Anhang unter der Nummer
 * seiner Meldung landet — auch wenn die noch nicht ausgewertet ist —, dass die
 * Datei auf dem Laufwerk liegt und nicht in der Datenbank, dass die Einordnung als
 * Bild oder Text aus dem gemeldeten Inhaltstyp kommt und dass beide Grenzen mit
 * einem nachvollziehbaren Grund greifen.
 */
class AttachmentIngestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function key(): ProjectKey
    {
        return Project::factory()->create()->keys()->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $itemHeaders
     */
    private function ingest(
        ProjectKey $key,
        string $content,
        array $itemHeaders = [],
        ?string $eventId = null,
    ): IngestPayload {
        $payload = IngestPayload::factory()
            ->viaKey($key)
            ->bytes($content, IngestType::Attachment, $itemHeaders, $eventId)
            ->create();

        ProcessIngestPayload::dispatch($payload);

        return $payload->refresh();
    }

    public function test_an_attachment_is_stored_under_the_event_it_was_sent_with(): void
    {
        $key = $this->key();
        $eventId = IngestPayload::freshEventId();

        $payload = $this->ingest($key, 'Zeile eins', [
            'filename' => 'protokoll.txt',
            'content_type' => 'text/plain',
        ], $eventId);

        $attachment = EventAttachment::query()->sole();

        $this->assertSame(ProcessingState::Processed, $payload->processing_state);
        $this->assertSame($key->project_id, $attachment->project_id);
        $this->assertSame($eventId, $attachment->event_reference);
        $this->assertSame($payload->id, $attachment->ingest_payload_id);
        $this->assertSame('protokoll.txt', $attachment->name);
        $this->assertSame('text/plain', $attachment->content_type);
        $this->assertSame(AttachmentKind::Text, $attachment->kind);
        $this->assertSame(10, $attachment->size);
        $this->assertSame(sha1('Zeile eins'), $attachment->checksum);

        // Der Inhalt liegt auf dem Laufwerk und nicht in der Zeile.
        Storage::disk('local')->assertExists($attachment->path);
        $this->assertSame('Zeile eins', Storage::disk('local')->get($attachment->path));
    }

    public function test_a_screenshot_keeps_its_bytes_and_is_classified_as_an_image(): void
    {
        $key = $this->key();

        // Ein Bild ist kein gültiges UTF-8 und enthält Nullbytes — beides Fälle,
        // an denen eine Textspalte den Inhalt sonst still beschädigt.
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAAAAAA6fptVAAAACklEQVR4nGMAAgAABQABDQottAAAAABJRU5ErkJggg==', true);
        $this->assertIsString($png);

        $this->ingest($key, $png, [
            'filename' => 'screenshot.png',
            'content_type' => 'image/png',
        ]);

        $attachment = EventAttachment::query()->sole();

        $this->assertSame(AttachmentKind::Image, $attachment->kind);
        $this->assertSame(strlen($png), $attachment->size);
        $this->assertSame($png, Storage::disk('local')->get($attachment->path));
    }

    public function test_an_unknown_content_type_stays_a_download(): void
    {
        // Auch ein Dokument, das der Browser auslegen würde: `text/html` steht
        // bewusst in keiner der beiden Vorschaulisten.
        foreach (['application/octet-stream', 'text/html', 'image/svg+xml', null] as $index => $contentType) {
            $headers = ['filename' => 'datei'.$index];

            if ($contentType !== null) {
                $headers['content_type'] = $contentType;
            }

            $this->ingest($this->key(), 'inhalt '.$index, $headers);
        }

        $kinds = EventAttachment::query()->pluck('kind')->all();

        $this->assertCount(4, $kinds);
        $this->assertSame([
            AttachmentKind::Binary,
            AttachmentKind::Binary,
            AttachmentKind::Binary,
            AttachmentKind::Binary,
        ], $kinds);
    }

    public function test_a_content_type_with_parameters_is_still_recognised(): void
    {
        $this->ingest($this->key(), 'a,b', [
            'filename' => 'werte.csv',
            'content_type' => 'text/csv; charset=utf-8',
        ]);

        $attachment = EventAttachment::query()->sole();

        $this->assertSame(AttachmentKind::Text, $attachment->kind);
        $this->assertSame('text/csv', $attachment->content_type);
    }

    public function test_a_reported_filename_cannot_carry_a_path(): void
    {
        $this->ingest($this->key(), 'inhalt', [
            'filename' => '../../etc/passwd',
            'content_type' => 'text/plain',
        ]);

        $this->assertSame('passwd', EventAttachment::query()->sole()->name);
    }

    public function test_an_attachment_without_a_filename_gets_one(): void
    {
        $this->ingest($this->key(), 'inhalt');

        $this->assertSame(EventAttachment::FALLBACK_NAME, EventAttachment::query()->sole()->name);
    }

    public function test_the_same_file_twice_is_stored_once(): void
    {
        $key = $this->key();

        $first = $this->ingest($key, 'derselbe Screenshot', ['filename' => 'a.txt', 'content_type' => 'text/plain']);
        $second = $this->ingest($key, 'derselbe Screenshot', ['filename' => 'b.txt', 'content_type' => 'text/plain']);

        $this->assertSame(ProcessingState::Processed, $first->processing_state);
        $this->assertSame(ProcessingState::Processed, $second->processing_state);

        $paths = EventAttachment::query()->pluck('path')->unique();

        // Zwei Zeilen, ein Pfad: der Ablageort ist die Prüfsumme.
        $this->assertCount(2, EventAttachment::query()->get());
        $this->assertCount(1, $paths);
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_a_repeated_job_does_not_attach_the_file_twice(): void
    {
        $key = $this->key();
        $payload = IngestPayload::factory()
            ->viaKey($key)
            ->bytes('einmal', IngestType::Attachment, ['filename' => 'a.txt', 'content_type' => 'text/plain'])
            ->create();

        ProcessIngestPayload::dispatch($payload);

        // Zweite Zustellung desselben Jobs: die Warteschlange darf das, und der
        // Zustand wird dafür zurückgesetzt, als wäre der erste Lauf abgebrochen.
        $payload->forceFill(['processing_state' => ProcessingState::Pending, 'processed_at' => null])->save();

        ProcessIngestPayload::dispatch($payload->fresh());

        $this->assertCount(1, EventAttachment::query()->get());
    }

    public function test_an_attachment_over_the_size_limit_is_discarded_and_counted(): void
    {
        config(['attachments.max_bytes' => 8]);

        $key = $this->key();
        $payload = $this->ingest($key, 'weit über der Grenze', [
            'filename' => 'gross.txt',
            'content_type' => 'text/plain',
        ]);

        $this->assertSame(ProcessingState::Dropped, $payload->processing_state);
        $this->assertSame(DiscardReason::TooLarge->value, $payload->failure);
        $this->assertCount(0, EventAttachment::query()->get());
        $this->assertSame([], Storage::disk('local')->allFiles());

        $discard = IngestDiscard::query()
            ->where('reason', DiscardReason::TooLarge->value)
            ->sole();

        $this->assertSame(IngestType::Attachment->value, $discard->category);
        $this->assertSame($key->id, $discard->project_key_id);
    }

    public function test_only_the_allowed_number_of_attachments_per_event_is_kept(): void
    {
        config(['attachments.max_per_event' => 2]);

        $key = $this->key();
        $eventId = IngestPayload::freshEventId();

        $accepted = [];

        foreach (['a', 'b', 'c'] as $index => $content) {
            $accepted[] = $this->ingest($key, $content, [
                'filename' => 'datei'.$index.'.txt',
                'content_type' => 'text/plain',
            ], $eventId);
        }

        $this->assertSame(ProcessingState::Processed, $accepted[0]->processing_state);
        $this->assertSame(ProcessingState::Processed, $accepted[1]->processing_state);
        $this->assertSame(ProcessingState::Dropped, $accepted[2]->processing_state);
        $this->assertSame(DiscardReason::TooManyItems->value, $accepted[2]->failure);

        $this->assertCount(2, EventAttachment::query()->get());

        // Die Grenze gilt je Meldung: eine zweite Nummer fängt wieder bei null an.
        $this->ingest($key, 'd', ['filename' => 'd.txt', 'content_type' => 'text/plain']);

        $this->assertCount(3, EventAttachment::query()->get());
    }

    public function test_a_project_that_does_not_store_attachments_keeps_nothing(): void
    {
        $key = $this->key();
        $key->project->update(['scrub_attachments' => true]);

        $payload = $this->ingest($key, 'geheim', [
            'filename' => 'formular.png',
            'content_type' => 'image/png',
        ]);

        $this->assertSame(ProcessingState::Dropped, $payload->processing_state);
        $this->assertSame(DiscardReason::Scrubbed->value, $payload->failure);
        $this->assertCount(0, EventAttachment::query()->get());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_an_unreadable_payload_is_discarded_instead_of_becoming_an_empty_file(): void
    {
        $key = $this->key();

        // Ein Beleg, der Daten ankündigt, aber keine lesbaren enthält: die
        // Base64-Spalte ist abgeschnitten. Ohne Prüfung entstünde daraus ein
        // 0-Byte-Anhang mit funktionierendem Download.
        $payload = IngestPayload::factory()
            ->viaKey($key)
            ->bytes('egal', IngestType::Attachment, ['filename' => 'a.png', 'content_type' => 'image/png'])
            ->create();

        $payload->forceFill([
            'payload' => 'nicht-base64!!',
            'payload_encoding' => IngestPayload::ENCODING_BASE64,
            'size_bytes' => 4711,
        ])->save();

        ProcessIngestPayload::dispatch($payload->fresh());

        $this->assertSame(ProcessingState::Dropped, $payload->refresh()->processing_state);
        $this->assertSame(DiscardReason::Unreadable->value, $payload->failure);
        $this->assertCount(0, EventAttachment::query()->get());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_an_empty_attachment_is_still_stored(): void
    {
        // Die Gegenprobe zum Fall darüber: eine wirklich leere Datei ist kein
        // Fehler, sondern eine leere Logdatei — sie soll ankommen.
        $this->ingest($this->key(), '', ['filename' => 'leer.txt', 'content_type' => 'text/plain']);

        $this->assertSame(0, EventAttachment::query()->sole()->size);
    }

    public function test_an_attachment_does_not_count_toward_the_event_quota(): void
    {
        // Die Zusage des Tasks, an der Stelle geprüft, an der sie steht: ein
        // Anhang ist eine Angabe über ein Ereignis und nicht selbst eines.
        $this->assertFalse(IngestType::Attachment->countsTowardEventQuota());
        $this->assertTrue(IngestType::Event->countsTowardEventQuota());
    }
}
