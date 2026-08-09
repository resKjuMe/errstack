<?php

namespace Tests\Feature\Attachments;

use App\Enums\IngestType;
use App\Models\Event;
use App\Models\EventAttachment;
use App\Models\EventGroup;
use App\Models\IngestPayload;
use App\Models\Issue;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Die Anhänge auf der Fehlerseite: was dort steht, was ausgeliefert wird und was
 * nicht.
 *
 * Zwei Zusagen sind hier wichtiger als die Anzeige selbst:
 *
 * **Nur Bild und Text dürfen inline in den Browser.** Ein Anhang kommt aus einer
 * überwachten Anwendung; ein als Bild gemeldetes HTML-Dokument wäre sonst ein Weg,
 * über unsere Adresse Code im Browser eines Teammitglieds auszuführen.
 *
 * **Alle drei Kennungen der Adresszeile werden geprüft.** Fehler, Meldung und
 * Anhang kommen als Kennungen daher und nicht aus einer Auswahl.
 */
class AttachmentPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Organization $organization;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->user = User::factory()->create();
        $this->organization = Organization::factory()->withMember($this->user)->create();
        $this->project = Project::factory()->for($this->organization)->create();

        $this->user->switchOrganization($this->organization);
    }

    private function issue(?Project $project = null): Issue
    {
        return Issue::factory()->for($project ?? $this->project)->create();
    }

    /**
     * Eine Meldung samt ihrer Gruppe.
     *
     * Der Fingerabdruck wird ausdrücklich vergeben und nicht der Fabrik
     * überlassen: die würfelt aus drei Ausnahmearten, und mehrere Gruppen **eines**
     * Projekts laufen dabei in den eindeutigen Index — hier entstehen mehrere,
     * weil die Prüfungen einen fremden Fehler daneben brauchen.
     */
    private function event(Issue $issue): Event
    {
        $group = EventGroup::factory()
            ->for($issue->project)
            ->for($issue)
            ->custom('anhaenge-'.$issue->id)
            ->create();

        return Event::factory()
            ->for($issue->project)
            ->create(['event_group_id' => $group->id]);
    }

    /**
     * Ein Anhang samt Datei auf dem Laufwerk — beides gehört zusammen, und ein
     * Test, der nur die Zeile anlegt, prüft das Ausliefern nicht.
     */
    private function attachment(Event $event, string $content, string $state = 'text', string $name = 'protokoll.txt'): EventAttachment
    {
        $factory = EventAttachment::factory()->forEvent($event);

        $factory = match ($state) {
            'image' => $factory->image($name),
            'binary' => $factory->binary($name),
            default => $factory->state(fn (): array => ['name' => $name]),
        };

        $checksum = sha1($content);

        /** @var EventAttachment $attachment */
        $attachment = $factory->create([
            'size' => strlen($content),
            'checksum' => $checksum,
            'path' => 'event-attachments/'.$event->project_id.'/'.substr($checksum, 0, 2).'/'.$checksum,
        ]);

        Storage::disk('local')->put($attachment->path, $content);

        return $attachment;
    }

    public function test_the_detail_page_lists_the_attachments_of_the_shown_event(): void
    {
        $issue = $this->issue();
        $event = $this->event($issue);

        $this->attachment($event, 'Zeile eins', 'text', 'protokoll.txt');
        $this->attachment($event, 'BILD', 'image', 'screenshot.png');

        $this->actingAs($this->user)
            ->get(route('issues.show', $issue))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('attachments.items', 2)
                ->where('attachments.items.0.name', 'protokoll.txt')
                ->where('attachments.items.0.kind', 'text')
                ->where('attachments.items.0.size', 10)
                ->where('attachments.items.1.name', 'screenshot.png')
                ->where('attachments.items.1.kind', 'image')
                // Die Frist gehört an die Anzeige: „warum ist der Screenshot von
                // letzter Woche weg" soll hier beantwortet sein.
                ->where('attachments.retentionDays', $this->project->attachment_retention_days)
                ->where('attachments.canDelete', true)
            );
    }

    public function test_a_file_without_preview_gets_no_preview_address(): void
    {
        $issue = $this->issue();
        $event = $this->event($issue);

        $this->attachment($event, 'binaer', 'binary', 'absturz.dmp');

        $this->actingAs($this->user)
            ->get(route('issues.show', $issue))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('attachments.items.0.kind', 'binary')
                ->where('attachments.items.0.previewHref', null)
            );
    }

    public function test_attachments_of_a_foreign_event_are_not_shown(): void
    {
        $issue = $this->issue();
        $event = $this->event($issue);
        $other = $this->event($this->issue());

        $this->attachment($other, 'fremd', 'text', 'fremd.txt');

        $this->actingAs($this->user)
            ->get(route('issues.events.show', [$issue, $event]))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('attachments.items', 0));
    }

    public function test_an_attachment_is_delivered_as_a_download(): void
    {
        $issue = $this->issue();
        $event = $this->event($issue);
        $attachment = $this->attachment($event, 'Zeile eins', 'text', 'protokoll.txt');

        $response = $this->actingAs($this->user)
            ->get(route('issues.attachments.show', [$issue, $event, $attachment]));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/octet-stream');
        $response->assertHeader('x-content-type-options', 'nosniff');
        $this->assertStringContainsString('attachment;', (string) $response->headers->get('content-disposition'));
        $this->assertStringContainsString('filename="protokoll.txt"', (string) $response->headers->get('content-disposition'));
        $this->assertSame('Zeile eins', $response->streamedContent());
    }

    public function test_a_download_never_carries_the_reported_content_type(): void
    {
        // Der Grund, warum der Typ beim Download nicht weitergegeben wird: ein
        // Dokument, das der Browser auslegt, soll er als Datei bekommen.
        $issue = $this->issue();
        $event = $this->event($issue);
        $attachment = $this->attachment($event, '<script>alert(1)</script>', 'binary', 'seite.html');

        $attachment->forceFill(['content_type' => 'text/html'])->save();

        $this->actingAs($this->user)
            ->get(route('issues.attachments.show', [$issue, $event, $attachment]))
            ->assertHeader('content-type', 'application/octet-stream');
    }

    public function test_an_image_is_delivered_inline(): void
    {
        $issue = $this->issue();
        $event = $this->event($issue);
        $attachment = $this->attachment($event, 'BILD', 'image', 'screenshot.png');

        $response = $this->actingAs($this->user)
            ->get(route('issues.attachments.preview', [$issue, $event, $attachment]));

        $response->assertOk();
        $response->assertHeader('content-type', 'image/png');
        $this->assertStringContainsString('inline;', (string) $response->headers->get('content-disposition'));
        $this->assertSame('BILD', $response->streamedContent());
    }

    public function test_a_text_preview_shows_only_the_beginning_and_stays_plain_text(): void
    {
        config(['attachments.preview.preview_bytes' => 5]);

        $issue = $this->issue();
        $event = $this->event($issue);
        $attachment = $this->attachment($event, 'Zeile eins und noch viel mehr', 'text', 'protokoll.txt');

        $attachment->forceFill(['content_type' => 'application/json'])->save();

        $response = $this->actingAs($this->user)
            ->get(route('issues.attachments.preview', [$issue, $event, $attachment]));

        $response->assertOk();
        // Nicht `application/json`: für den Browser ist das ein Dokument, das er
        // auslegt — für eine Vorschau in einem `<pre>` ist genau das nicht gewollt.
        $response->assertHeader('content-type', 'text/plain; charset=utf-8');
        $this->assertSame('Zeile', $response->getContent());

        // Dass gekürzt wurde, sagt die Anzeige und nicht der Anriss selbst.
        $this->actingAs($this->user)
            ->get(route('issues.show', $issue))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('attachments.items.0.previewTruncated', true)
            );
    }

    public function test_a_text_preview_never_ends_in_a_broken_character(): void
    {
        // Die Grenze fällt mitten in das zwei Byte lange „ü": geschnitten wird nach
        // Bytes, ausgeliefert wird als UTF-8.
        config(['attachments.preview.preview_bytes' => 9]);

        $issue = $this->issue();
        $event = $this->event($issue);
        $attachment = $this->attachment($event, 'Zahlung fehlgeschlagen', 'text', 'protokoll.txt');

        $response = $this->actingAs($this->user)
            ->get(route('issues.attachments.preview', [$issue, $event, $attachment]));

        $response->assertOk();

        $preview = (string) $response->getContent();

        $this->assertSame('Zahlung f', $preview);
        $this->assertTrue(mb_check_encoding($preview, 'UTF-8'));
    }

    public function test_deleting_an_attachment_also_removes_its_raw_payload(): void
    {
        $issue = $this->issue();
        $event = $this->event($issue);
        $payload = IngestPayload::factory()
            ->for($this->project)
            ->bytes('geheim', IngestType::Attachment, ['filename' => 'a.txt', 'content_type' => 'text/plain'])
            ->create();

        $attachment = $this->attachment($event, 'geheim');
        $attachment->forceFill(['ingest_payload_id' => $payload->id])->save();

        $this->actingAs($this->user)
            ->delete(route('issues.attachments.destroy', [$issue, $event, $attachment]));

        // Sonst wäre das Löschen keines: die Rohdaten sind eine zweite Kopie
        // derselben Bytes, und ein erneut eingereihter Beleg legte den gelöschten
        // Screenshot wieder an.
        $this->assertNull($attachment->fresh());
        $this->assertNull($payload->fresh());
    }

    public function test_a_file_without_preview_has_no_preview_route(): void
    {
        $issue = $this->issue();
        $event = $this->event($issue);
        $attachment = $this->attachment($event, 'binaer', 'binary', 'absturz.dmp');

        $this->actingAs($this->user)
            ->get(route('issues.attachments.preview', [$issue, $event, $attachment]))
            ->assertNotFound();
    }

    public function test_a_missing_file_is_a_missing_attachment_and_not_an_error(): void
    {
        $issue = $this->issue();
        $event = $this->event($issue);
        $attachment = $this->attachment($event, 'Zeile eins');

        Storage::disk('local')->delete($attachment->path);

        $this->actingAs($this->user)
            ->get(route('issues.attachments.show', [$issue, $event, $attachment]))
            ->assertNotFound();
    }

    public function test_an_attachment_of_another_event_is_not_reachable_through_this_one(): void
    {
        $issue = $this->issue();
        $event = $this->event($issue);
        $foreign = $this->attachment($this->event($this->issue()), 'fremd');

        $this->actingAs($this->user)
            ->get(route('issues.attachments.show', [$issue, $event, $foreign]))
            ->assertNotFound();
    }

    public function test_a_stranger_reaches_nothing(): void
    {
        $issue = $this->issue();
        $event = $this->event($issue);
        $attachment = $this->attachment($event, 'Zeile eins');

        $stranger = User::factory()->create();
        $stranger->switchOrganization(Organization::factory()->withMember($stranger)->create());

        $this->actingAs($stranger)
            ->get(route('issues.attachments.show', [$issue, $event, $attachment]))
            ->assertForbidden();
    }

    public function test_an_attachment_can_be_deleted_individually(): void
    {
        $issue = $this->issue();
        $event = $this->event($issue);
        $first = $this->attachment($event, 'Zeile eins', 'text', 'a.txt');
        $second = $this->attachment($event, 'Zeile zwei', 'text', 'b.txt');

        $this->actingAs($this->user)
            ->from(route('issues.show', $issue))
            ->delete(route('issues.attachments.destroy', [$issue, $event, $first]))
            ->assertRedirect(route('issues.show', $issue));

        $this->assertNull($first->fresh());
        $this->assertNotNull($second->fresh());

        // Die Datei fällt mit — eine Zeile ohne Datei wäre Platz, den niemand
        // mehr findet.
        Storage::disk('local')->assertMissing($first->path);
        Storage::disk('local')->assertExists($second->path);
    }

    public function test_deleting_one_of_two_identical_files_keeps_the_content(): void
    {
        $issue = $this->issue();
        $event = $this->event($issue);
        $first = $this->attachment($event, 'derselbe Inhalt', 'text', 'a.txt');
        $second = $this->attachment($event, 'derselbe Inhalt', 'text', 'b.txt');

        $this->assertSame($first->path, $second->path);

        $this->actingAs($this->user)
            ->delete(route('issues.attachments.destroy', [$issue, $event, $first]));

        $this->assertNull($first->fresh());
        Storage::disk('local')->assertExists($second->path);
    }

    public function test_a_stranger_cannot_delete(): void
    {
        $issue = $this->issue();
        $event = $this->event($issue);
        $attachment = $this->attachment($event, 'Zeile eins');

        $stranger = User::factory()->create();
        $stranger->switchOrganization(Organization::factory()->withMember($stranger)->create());

        $this->actingAs($stranger)
            ->delete(route('issues.attachments.destroy', [$issue, $event, $attachment]))
            ->assertForbidden();

        $this->assertNotNull($attachment->fresh());
    }
}
