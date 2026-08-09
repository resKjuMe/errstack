<?php

namespace Tests\Unit;

use App\Support\Replays\ReplayRecording;
use App\Support\Replays\ReplayTimeline;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Das Lesen eines Aufzeichnungs-Abschnitts.
 *
 * Hier steht die Formtreue auf dem Prüfstand und nicht die Fachlichkeit. Das
 * Element `replay_recording` ist das einzige der ganzen Aufnahme, das kein JSON
 * ist und trotzdem eines enthält, und es hat drei Eigenheiten, an denen sich
 * ein Leser sicher verheddert:
 *
 *   1. Der Inhalt kommt mit zlib gepackt, mit gzip gepackt oder blank — je nach
 *      SDK-Fassung und Einstellung.
 *   2. Die Kopfzeile ist optional; ältere SDKs schicken die Nummer nur im Kopf
 *      des Envelope-Elements.
 *   3. **rrweb zählt in Millisekunden**, die übrigen SDK-Felder in Sekunden.
 *      Eine Sekunden-Auslegung ergibt Zeitpunkte im Jahr 58026 — die nimmt
 *      keine Datenbank an, und der Abschnitt wäre ein Fehlschlag statt eines
 *      Films.
 */
class ReplayRecordingTest extends TestCase
{
    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function body(array $events, ?int $segmentId = 0, string $packing = 'zlib'): string
    {
        $json = (string) json_encode($events);

        $payload = match ($packing) {
            'zlib' => (string) gzcompress($json),
            'gzip' => (string) gzencode($json),
            default => $json,
        };

        return $segmentId === null
            ? $payload
            : (string) json_encode(['segment_id' => $segmentId])."\n".$payload;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function events(int $startMs): array
    {
        return [
            ['type' => 4, 'timestamp' => $startMs, 'data' => ['href' => 'https://example.com/']],
            ['type' => 3, 'timestamp' => $startMs + 2_000, 'data' => ['source' => 2]],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function packings(): array
    {
        return ['zlib' => ['zlib'], 'gzip' => ['gzip'], 'ungepackt' => ['none']];
    }

    #[DataProvider('packings')]
    public function test_every_packing_in_the_wild_is_read(string $packing): void
    {
        $startMs = 1_770_000_000_000;

        $recording = ReplayRecording::fromBytes($this->body($this->events($startMs), 3, $packing), null, 1000);

        $this->assertNotNull($recording);
        $this->assertSame(3, $recording->segmentId);
        $this->assertCount(2, $recording->events);
        $this->assertSame($startMs, $recording->startedAt->getTimestampMs());
        $this->assertSame($startMs + 2_000, $recording->endedAt->getTimestampMs());
    }

    /**
     * Ohne Kopfzeile muss die Nummer aus dem Element-Kopf kommen. Fehlt auch
     * die, gehört der Abschnitt zu keiner Reihenfolge — und ein Film ohne
     * Reihenfolge ist keiner.
     */
    public function test_the_segment_number_may_come_from_the_item_header(): void
    {
        $body = $this->body($this->events(1_770_000_000_000), segmentId: null);

        $this->assertSame(7, ReplayRecording::fromBytes($body, 7, 1000)?->segmentId);
        $this->assertNull(ReplayRecording::fromBytes($body, null, 1000));
    }

    /**
     * Ein gepackter Datenstrom enthält Zeilenumbrüche als gewöhnliche Bytes.
     * Den ersten davon für eine Kopfzeile zu halten hieße, den Anfang des Films
     * abzuschneiden.
     */
    public function test_a_newline_inside_the_payload_is_not_mistaken_for_a_header(): void
    {
        $recording = ReplayRecording::fromBytes(
            $this->body($this->events(1_770_000_000_000), segmentId: null),
            0,
            1000,
        );

        $this->assertNotNull($recording);
        $this->assertCount(2, $recording->events);
    }

    public function test_events_beyond_the_limit_are_counted_not_silently_dropped(): void
    {
        $startMs = 1_770_000_000_000;
        $events = [];

        for ($i = 0; $i < 10; $i++) {
            $events[] = ['type' => 3, 'timestamp' => $startMs + $i, 'data' => []];
        }

        $recording = ReplayRecording::fromBytes($this->body($events), null, 4);

        $this->assertNotNull($recording);
        $this->assertCount(4, $recording->events);
        $this->assertSame(6, $recording->droppedEvents);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function garbage(): array
    {
        return [
            'leer' => [''],
            'kein json' => ["{\"segment_id\":0}\nnicht json"],
            'objekt statt liste' => ["{\"segment_id\":0}\n{\"a\":1}"],
            'leere liste' => ["{\"segment_id\":0}\n[]"],
        ];
    }

    #[DataProvider('garbage')]
    public function test_unreadable_bodies_yield_nothing(string $body): void
    {
        $this->assertNull(ReplayRecording::fromBytes($body, 0, 1000));
    }

    /**
     * Die Einstellungen des SDK stecken als eigenes Ereignis im ersten
     * Abschnitt — und darin steht die einzige Auskunft zur Maskierung, die
     * diese Anwendung überhaupt bekommen kann.
     */
    public function test_masking_is_read_from_the_sdk_options(): void
    {
        $options = static fn (bool $text, bool $inputs): array => [[
            'type' => 5,
            'timestamp' => 1_770_000_000_000,
            'data' => [
                'tag' => 'options',
                'payload' => ['maskAllText' => $text, 'maskAllInputs' => $inputs],
            ],
        ]];

        $this->assertTrue(ReplayTimeline::maskingFrom($options(true, true)));
        $this->assertFalse(ReplayTimeline::maskingFrom($options(true, false)));
        $this->assertFalse(ReplayTimeline::maskingFrom($options(false, true)));

        // Ohne das Ereignis gibt es keine Aussage — und keine Aussage ist nicht
        // dasselbe wie „nicht maskiert". Ein SDK, das die Einstellungen nicht
        // mitschickt, bekäme sonst eine Warnung, die immer leuchtet.
        $this->assertNull(ReplayTimeline::maskingFrom([
            ['type' => 3, 'timestamp' => 1_770_000_000_000, 'data' => []],
        ]));
    }
}
