<?php

namespace Tests\Unit;

use App\Models\Transaction;
use App\Support\Performance\Detection\Detector;
use App\Support\Performance\Detection\Detectors\CacheMisses;
use App\Support\Performance\Detection\Detectors\ConsecutiveQueries;
use App\Support\Performance\Detection\Detectors\DuplicateQueries;
use App\Support\Performance\Detection\Detectors\MainThreadBlock;
use App\Support\Performance\Detection\Detectors\NPlusOneQueries;
use App\Support\Performance\Detection\Detectors\OversizedAsset;
use App\Support\Performance\Detection\Detectors\RenderBlockingAsset;
use App\Support\Performance\Detection\Detectors\SlowHttpCall;
use App\Support\Performance\Detection\Finding;
use App\Support\Performance\Detection\QueryShape;
use App\Support\Performance\Detection\SpanRecord;
use App\Support\Performance\Detection\Thresholds;
use App\Support\Performance\Detection\TraceSnapshot;
use Tests\TestCase;

/**
 * Die Erkenner, jeder für sich.
 *
 * **Ohne Datenbank.** Das ist keine Sparmaßnahme, sondern die Probe auf die
 * Bauart: ein Erkenner, der etwas anderes tut als Schritte anzusehen und Funde
 * zurückzugeben, ließe sich hier nicht prüfen. Solange dieser Test ohne
 * Migration läuft, stimmt die Trennung zwischen „erkennen" und „aufschreiben".
 *
 * Geprüft wird zu jedem Muster beides: dass es anschlägt, **und** dass es bei
 * dem naheliegenden Gegenbeispiel schweigt. Nur die erste Hälfte zu prüfen
 * ließe einen Erkenner durchgehen, der immer etwas findet.
 */
class PerformanceDetectorsTest extends TestCase
{
    public function test_n_plus_one_needs_a_source_query(): void
    {
        $findings = $this->detect(new NPlusOneQueries, [
            // Die auslösende Abfrage …
            $this->span('s0', 'db.sql.query', 0, 10_000, 'select * from orders where user_id = 1'),
            // … und die Serie danach.
            ...$this->series('select * from items where order_id = ', 5, 15_000),
        ]);

        $this->assertCount(1, $findings);
        $this->assertSame(
            QueryShape::of('select * from items where order_id = 1'),
            $findings[0]->subject,
        );
        $this->assertSame(5, $findings[0]->evidence['repeats']);

        // Vier der fünf Abfragen fielen weg — die erste bliebe.
        $this->assertSame(4 * 15_000, $findings[0]->timeLostUs);
    }

    public function test_n_plus_one_stays_silent_without_a_source_query(): void
    {
        // Dieselbe Serie, aber nichts davor, aus dem sie stammen könnte.
        $findings = $this->detect(new NPlusOneQueries, $this->series('select * from items where order_id = ', 5, 15_000));

        $this->assertSame([], $findings);
    }

    public function test_consecutive_queries_need_to_wait_for_each_other(): void
    {
        $serial = $this->detect(new ConsecutiveQueries, $this->series('select * from items where order_id = ', 4, 30_000));

        $this->assertCount(1, $serial);
        // Gebündelt bliebe die längste übrig; hier sind alle gleich lang.
        $this->assertSame(3 * 30_000, $serial[0]->timeLostUs);

        // Dieselben vier Abfragen, aber gleichzeitig gestartet: nichts zu holen.
        $parallel = $this->detect(new ConsecutiveQueries, [
            $this->span('p1', 'db.sql.query', 0, 40_000, 'select * from items where order_id = 1'),
            $this->span('p2', 'db.sql.query', 1_000, 40_000, 'select * from items where order_id = 2'),
            $this->span('p3', 'db.sql.query', 2_000, 40_000, 'select * from items where order_id = 3'),
            $this->span('p4', 'db.sql.query', 3_000, 40_000, 'select * from items where order_id = 4'),
        ]);

        $this->assertSame([], $parallel);
    }

    public function test_duplicate_queries_compare_the_text_and_not_the_shape(): void
    {
        $identical = $this->detect(new DuplicateQueries, [
            $this->span('d1', 'db.sql.query', 0, 20_000, 'select * from settings where key = 42'),
            $this->span('d2', 'db.sql.query', 40_000, 20_000, 'select * from settings where key = 42'),
        ]);

        $this->assertCount(1, $identical);
        $this->assertSame(20_000, $identical[0]->timeLostUs);

        // Gleichartig, aber nicht gleich: das ist Sache der Serien-Erkenner.
        $similar = $this->detect(new DuplicateQueries, [
            $this->span('s1', 'db.sql.query', 0, 20_000, 'select * from settings where key = 42'),
            $this->span('s2', 'db.sql.query', 40_000, 20_000, 'select * from settings where key = 43'),
        ]);

        $this->assertSame([], $similar);
    }

    public function test_slow_http_call_reports_only_the_time_above_the_threshold(): void
    {
        $findings = $this->detect(new SlowHttpCall, [
            $this->span('h1', 'http.client', 0, 1_500_000, 'GET https://api.example.com/kunden/4711', [
                'url.full' => 'https://api.example.com/kunden/4711?zeit=1',
                'http.request.method' => 'GET',
            ]),
        ]);

        $this->assertCount(1, $findings);
        // Die Vorgabeschwelle ist eine Sekunde: eine halbe bleibt übrig.
        $this->assertSame(500_000, $findings[0]->timeLostUs);
        // Ohne Kennung und ohne Abfrageteil — sonst wäre jeder Kunde ein
        // eigener Eintrag.
        $this->assertSame('https://api.example.com/kunden/?', $findings[0]->subject);
    }

    public function test_oversized_asset_recognises_a_missing_compression(): void
    {
        $findings = $this->detect(new OversizedAsset, [
            $this->span('a1', 'resource.script', 0, 400_000, 'https://example.com/app.js', [
                'http.response_content_length' => 300 * 1024,
                'http.decoded_response_content_length' => 300 * 1024,
            ]),
        ]);

        // Unter der Größenschwelle von 500 KB, aber nachweislich unkomprimiert.
        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->evidence['uncompressed']);

        $compressed = $this->detect(new OversizedAsset, [
            $this->span('a2', 'resource.script', 0, 400_000, 'https://example.com/app.js', [
                'http.response_content_length' => 300 * 1024,
                'http.decoded_response_content_length' => 900 * 1024,
            ]),
        ]);

        $this->assertSame([], $compressed);
    }

    public function test_render_blocking_asset_trusts_the_browser_and_does_not_guess(): void
    {
        $blocking = $this->detect(new RenderBlockingAsset, [
            $this->span('r1', 'resource.link', 0, 500_000, 'https://example.com/app.css', [
                'resource.render_blocking_status' => 'blocking',
            ]),
        ]);

        $this->assertCount(1, $blocking);
        $this->assertSame(500_000, $blocking[0]->timeLostUs);

        // Dieselbe Datei, dieselbe Dauer — nur ohne die Angabe des Browsers.
        $unknown = $this->detect(new RenderBlockingAsset, [
            $this->span('r2', 'resource.link', 0, 500_000, 'https://example.com/app.css'),
        ]);

        $this->assertSame([], $unknown);
    }

    public function test_main_thread_block_counts_only_the_time_above_fifty_milliseconds(): void
    {
        $findings = $this->detect(new MainThreadBlock, [
            $this->span('t1', 'ui.long-task', 0, 250_000, 'Layout'),
        ]);

        $this->assertCount(1, $findings);
        $this->assertSame(200_000, $findings[0]->timeLostUs);
    }

    public function test_cache_misses_ignore_lookups_without_an_answer(): void
    {
        $misses = [];

        for ($i = 0; $i < 5; $i++) {
            $misses[] = $this->span('c'.$i, 'cache.get', $i * 10_000, 5_000, 'nutzer:'.(4711 + $i), [
                'cache.hit' => false,
                'cache.key' => 'nutzer:'.(4711 + $i),
            ]);
        }

        $findings = $this->detect(new CacheMisses, $misses);

        $this->assertCount(1, $findings);
        $this->assertSame(5, $findings[0]->evidence['misses']);
        $this->assertSame(5 * 5_000, $findings[0]->timeLostUs);

        // Dieselben Zugriffe ohne die Angabe `cache.hit`: unbekannt ist kein
        // Fehlgriff.
        $silent = array_map(
            fn (SpanRecord $span): SpanRecord => $this->span(
                $span->spanId,
                'cache.get',
                $span->startedUs,
                $span->durationUs,
                (string) $span->description,
            ),
            $misses,
        );

        $this->assertSame([], $this->detect(new CacheMisses, $silent));
    }

    /**
     * @param  list<SpanRecord>  $spans
     * @return list<Finding>
     */
    private function detect(Detector $detector, array $spans): array
    {
        $transaction = new Transaction;
        $transaction->name = 'GET /bestellungen';

        return $detector->detect(
            TraceSnapshot::fromSpans($transaction, $spans),
            Thresholds::defaults(),
        );
    }

    /**
     * Eine Serie gleichartiger Abfragen, die einander abwarten.
     *
     * @return list<SpanRecord>
     */
    private function series(string $prefix, int $count, int $durationUs): array
    {
        $spans = [];

        for ($i = 0; $i < $count; $i++) {
            // Doppelter Abstand zur Dauer: so endet jede Abfrage, bevor die
            // nächste beginnt — genau die Bedingung, an der die Serien-Erkenner
            // „nacheinander" von „nebeneinander" unterscheiden.
            $spans[] = $this->span(
                'n'.$i,
                'db.sql.query',
                20_000 + $i * $durationUs * 2,
                $durationUs,
                $prefix.($i + 1),
            );
        }

        return $spans;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function span(
        string $spanId,
        string $op,
        int $startedUs,
        int $durationUs,
        string $description,
        array $data = [],
    ): SpanRecord {
        return new SpanRecord(
            spanId: $spanId,
            parentSpanId: 'abcdef1234567890',
            op: $op,
            description: $description,
            startedUs: $startedUs,
            durationUs: $durationUs,
            position: 0,
            data: $data,
            shape: QueryShape::of($description),
        );
    }
}
