<?php

namespace Tests\Feature\SourceMaps;

use App\Enums\SymbolicationDiagnosis;
use App\Enums\SymbolicationStatus;
use App\Jobs\SymbolicateEvent;
use App\Models\Event;
use App\Models\EventGroup;
use App\Models\EventSymbolication;
use App\Models\Issue;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Release;
use App\Models\User;
use App\Support\SourceMaps\ArtifactStore;
use App\Support\SourceMaps\Symbolicator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;
use Tests\Unit\SourceMapTest;

/**
 * Die Rückübersetzung eines minimierten Stacktraces — vom hochgeladenen Artefakt
 * bis zum lesbaren Rahmen.
 *
 * Die Karte ist dieselbe wie im Kartenleser-Test und ebenso von Hand gerechnet:
 * eine erzeugte Zeile, ein Eintrag bei Spalte 10, der auf `src/warenkorb.ts`
 * Zeile 3 zeigt. Die Herleitung steht in {@see SourceMapTest}.
 *
 * Geprüft wird hier das, was zwischen Artefakt und Rahmen liegt: **welche** Karte
 * gefunden wird, was passiert, wenn keine passt, und dass das Ereignis dabei
 * unangetastet bleibt.
 */
class SymbolicationTest extends TestCase
{
    use RefreshDatabase;

    private const DEBUG_ID = '5a2b1c3d-4e5f-6071-8293-a4b5c6d7e8f9';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function map(?string $debugId = null): string
    {
        return (string) json_encode(array_filter([
            'version' => 3,
            'sources' => ['webpack:///./src/warenkorb.ts'],
            'sourcesContent' => ["const a = 1;\nconst b = 2;\nreturn posten.reduce(fn);\nconst d = 4;\n"],
            'names' => ['berechneSumme'],
            'mappings' => 'AAAA,UAEIA',
            'debug_id' => $debugId,
        ], static fn (mixed $value): bool => $value !== null));
    }

    /**
     * Eine Meldung mit einem minimierten Rahmen — Zeile 1, Spalte 11.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function event(Project $project, array $overrides = []): Event
    {
        return Event::factory()->create(array_replace([
            'project_id' => $project->id,
            'platform' => 'javascript',
            'release' => '1.3.0',
            'exceptions' => [[
                'type' => 'TypeError',
                'value' => "Cannot read properties of undefined (reading 'summe')",
                'frames' => [[
                    'filename' => 'https://example.com/static/js/app.js',
                    'abs_path' => 'https://example.com/static/js/app.js',
                    'function' => 'a.b.c',
                    'lineno' => 1,
                    'colno' => 11,
                ]],
            ]],
        ], $overrides));
    }

    private function store(): ArtifactStore
    {
        return app(ArtifactStore::class);
    }

    /**
     * @return array{Project, Release}
     */
    private function context(): array
    {
        $project = Project::factory()->create();

        return [$project, Release::forVersion($project, '1.3.0')];
    }

    public function test_a_frame_is_translated_through_the_bundle_to_its_source_map(): void
    {
        [$project, $release] = $this->context();

        $this->store()->put($release, '~/static/js/app.js', "var a=1;\n//# sourceMappingURL=app.js.map\n");
        $this->store()->put($release, '~/static/js/app.js.map', $this->map());

        $result = app(Symbolicator::class)->symbolicate($this->event($project));

        $this->assertSame(SymbolicationStatus::Mapped, $result->status);
        $this->assertSame(1, $result->mappedFrames);
        $this->assertSame(1, $result->totalFrames);

        $frame = $result->exceptions[0]['frames'][0];

        // Das Präfix des Bauwerkzeugs fällt weg: wer die Datei in seinem Editor
        // sucht, sucht ohne es.
        $this->assertSame('src/warenkorb.ts', $frame['filename']);
        $this->assertSame(3, $frame['lineno']);
        $this->assertSame(5, $frame['colno']);
        $this->assertSame('berechneSumme', $frame['function']);
        $this->assertTrue($frame['in_app']);

        // Der Quelltext kommt aus der Karte selbst.
        $this->assertSame('return posten.reduce(fn);', $frame['context_line']);
        $this->assertSame(['const a = 1;', 'const b = 2;'], $frame['pre_context']);

        // Die Rückfahrkarte: eine Karte aus einem anderen Bau liefert plausible
        // falsche Zeilen, und dies ist die einzige Stelle, an der das auffällt.
        $this->assertSame('https://example.com/static/js/app.js', $frame['minified']['filename']);
        $this->assertSame('a.b.c', $frame['minified']['function']);
        $this->assertSame(1, $frame['minified']['lineno']);
    }

    public function test_the_debug_id_matches_without_a_release_or_a_path(): void
    {
        [$project, $release] = $this->context();

        // Das Artefakt liegt unter einem Pfad, den die Meldung nie nennt, und die
        // Meldung selbst trägt keine Version — über die Kennung wird es trotzdem
        // gefunden. Das ist der Fall „Bundle hinter einem Auslieferungsnetz".
        $this->store()->put($release, '~/irgendwo/anders.js.map', $this->map(self::DEBUG_ID));

        $event = $this->event($project, [
            'release' => null,
            'debug_meta' => ['images' => [[
                'type' => 'sourcemap',
                'code_file' => 'https://cdn.example.net/assets/app.js',
                'debug_id' => self::DEBUG_ID,
            ]]],
            'exceptions' => [[
                'type' => 'TypeError',
                'frames' => [[
                    'abs_path' => 'https://cdn.example.net/assets/app.js',
                    'function' => 'x',
                    'lineno' => 1,
                    'colno' => 11,
                ]],
            ]],
        ]);

        $result = app(Symbolicator::class)->symbolicate($event);

        $this->assertSame(SymbolicationStatus::Mapped, $result->status);
        $this->assertSame('src/warenkorb.ts', $result->exceptions[0]['frames'][0]['filename']);
    }

    public function test_a_missing_upload_is_reported_as_a_reason_and_not_as_silence(): void
    {
        [$project] = $this->context();

        $result = app(Symbolicator::class)->symbolicate($this->event($project));

        $this->assertSame(SymbolicationStatus::Unmapped, $result->status);
        $this->assertSame(
            [SymbolicationDiagnosis::NoArtifacts->value],
            array_column($result->diagnostics(), 'reason')
        );
        $this->assertSame('1.3.0', $result->diagnostics()[0]['detail']);

        // Der Rahmen bleibt in der Liste — nur übersetzt ist er nicht.
        $this->assertSame(
            'https://example.com/static/js/app.js',
            $result->exceptions[0]['frames'][0]['filename']
        );
    }

    public function test_an_event_without_a_release_says_so(): void
    {
        [$project] = $this->context();

        $result = app(Symbolicator::class)->symbolicate($this->event($project, ['release' => null]));

        $this->assertSame(
            [SymbolicationDiagnosis::NoRelease->value],
            array_column($result->diagnostics(), 'reason')
        );
    }

    public function test_a_path_that_no_artifact_matches_is_a_different_reason(): void
    {
        [$project, $release] = $this->context();

        $this->store()->put($release, '~/static/js/anders.js', 'var a=1;');

        $result = app(Symbolicator::class)->symbolicate($this->event($project));

        // „Diese Version hat keine Artefakte" verlangt einen Schritt in der
        // Bauumgebung, „diese Datei ist nicht darunter" einen Blick auf die
        // Adressen. Beides als ein Grund zu melden wäre unbrauchbar.
        $this->assertSame(
            [SymbolicationDiagnosis::ArtifactNotFound->value],
            array_column($result->diagnostics(), 'reason')
        );
    }

    public function test_a_bundle_without_a_map_is_told_apart_from_a_map_that_is_missing(): void
    {
        [$project, $release] = $this->context();

        // Bundle ohne jeden Verweis, und die Version hat mehr als eine Karte —
        // damit greift auch die Vermutung „die einzige Karte" nicht.
        $this->store()->put($release, '~/static/js/app.js', 'var a=1;');
        $this->store()->put($release, '~/eine.js.map', $this->map());
        $this->store()->put($release, '~/zwei.js.map', $this->map());

        $result = app(Symbolicator::class)->symbolicate($this->event($project));

        $this->assertSame(
            [SymbolicationDiagnosis::NoSourceMapReference->value],
            array_column($result->diagnostics(), 'reason')
        );

        // Mit Verweis, aber ohne die Datei: die Karte wurde gebaut und nicht
        // hochgeladen — ein anderer Handgriff.
        $this->store()->put($release, '~/static/js/app.js', "var a=1;\n//# sourceMappingURL=app.js.map\n");

        $second = app(Symbolicator::class)->symbolicate($this->event($project));

        $this->assertSame(
            [SymbolicationDiagnosis::SourceMapMissing->value],
            array_column($second->diagnostics(), 'reason')
        );
    }

    public function test_an_unreadable_map_is_reported_as_such(): void
    {
        [$project, $release] = $this->context();

        $this->store()->put($release, '~/static/js/app.js', "var a=1;\n//# sourceMappingURL=app.js.map\n");
        // Das Artefakt gilt als Karte, weil es `mappings` enthält — lesbar ist es
        // deshalb nicht.
        $this->store()->put($release, '~/static/js/app.js.map', '{"mappings": nope}');

        $result = app(Symbolicator::class)->symbolicate($this->event($project));

        $this->assertSame(
            [SymbolicationDiagnosis::InvalidSourceMap->value],
            array_column($result->diagnostics(), 'reason')
        );
    }

    public function test_frames_that_were_never_meant_produce_no_reason(): void
    {
        [$project] = $this->context();

        $event = $this->event($project, [
            'exceptions' => [[
                'type' => 'RuntimeException',
                'frames' => [[
                    'filename' => 'app/Http/Controllers/ReportController.php',
                    'function' => 'handle',
                    'lineno' => 42,
                    'in_app' => true,
                ]],
            ]],
        ]);

        $this->assertFalse(Symbolicator::isApplicable($event));

        $result = app(Symbolicator::class)->symbolicate($event);

        // Kein Kandidat, kein Grund: ein Rahmen aus dem Backend ist nicht
        // gescheitert, er war nie gemeint.
        $this->assertSame(0, $result->totalFrames);
        $this->assertSame([], $result->diagnostics());
    }

    public function test_the_same_reason_is_summarised_across_frames(): void
    {
        [$project] = $this->context();

        $frame = [
            'abs_path' => 'https://example.com/static/js/app.js',
            'lineno' => 1,
            'colno' => 11,
        ];

        $result = app(Symbolicator::class)->symbolicate($this->event($project, [
            'exceptions' => [[
                'type' => 'TypeError',
                'frames' => [$frame, $frame, $frame],
            ]],
        ]));

        // Drei gleichlautende Zeilen wären Lärm; eine Zeile mit „3×" ist eine
        // Aussage.
        $this->assertCount(1, $result->diagnostics());
        $this->assertSame(3, $result->diagnostics()[0]['count']);
    }

    public function test_the_original_event_is_left_untouched_and_the_result_is_cached(): void
    {
        [$project, $release] = $this->context();

        $this->store()->put($release, '~/static/js/app.js', "var a=1;\n//# sourceMappingURL=app.js.map\n");
        $this->store()->put($release, '~/static/js/app.js.map', $this->map());

        $event = $this->event($project);
        $before = $event->exceptions;

        (new SymbolicateEvent($event))->handle(app(Symbolicator::class));

        // An den gemeldeten Rahmen hängt die Gruppierung — sie zu überschreiben
        // würde einen laufenden Fehler in zwei spalten.
        $this->assertSame($before, $event->fresh()?->exceptions);

        $record = EventSymbolication::query()->firstOrFail();

        $this->assertSame(SymbolicationStatus::Mapped, $record->status);
        $this->assertSame(1, $record->mapped_frames);
        $this->assertNotNull($record->duration_ms);

        // Ein zweiter Lauf rechnet nicht neu: die Zeile **ist** der
        // Zwischenspeicher. Die Karte von der Platte zu holen kostet bei einem
        // echten Bundle zweistellige Megabyte.
        $record->forceFill(['mapped_frames' => 99])->save();

        (new SymbolicateEvent($event))->handle(app(Symbolicator::class));

        $this->assertSame(99, $record->fresh()?->mapped_frames);
    }

    public function test_an_upload_invalidates_what_failed_for_want_of_artifacts(): void
    {
        [$project, $release] = $this->context();

        $event = $this->event($project);

        (new SymbolicateEvent($event))->handle(app(Symbolicator::class));

        $this->assertSame(SymbolicationStatus::Unmapped, EventSymbolication::query()->firstOrFail()->status);

        $this->store()->put($release, '~/static/js/app.js', "var a=1;\n//# sourceMappingURL=app.js.map\n");
        $this->store()->invalidateSymbolications($release);

        // Weggeworfen und nicht neu gerechnet: gebraucht wird die Übersetzung
        // erst, wenn jemand hinsieht — ein Upload von zweihundert Dateien würde
        // sonst zweihundertmal dieselben Aufträge einreihen.
        $this->assertSame(0, EventSymbolication::query()->count());

        // Ein vollständiges Ergebnis bleibt dagegen stehen: es ist nicht überholt.
        $mapped = EventSymbolication::query()->create([
            'event_id' => $this->event($project)->id,
            'project_id' => $project->id,
            'status' => SymbolicationStatus::Mapped,
        ]);

        $this->store()->invalidateSymbolications($release);

        $this->assertNotNull($mapped->fresh());
    }

    public function test_opening_the_issue_page_queues_a_translation_and_shows_that_it_runs(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user)->create();
        $project = Project::factory()->for($organization)->create();
        $user->switchOrganization($organization);

        Release::forVersion($project, '1.3.0');

        $issue = Issue::factory()->for($project)->create();
        $group = EventGroup::factory()->for($project)->for($issue)->create();

        $this->event($project, ['event_group_id' => $group->id]);

        // **Der eigentliche Auslöser.** Quellkarten kommen in der Praxis nach den
        // ersten Fehlern; ein Auftrag nur bei der Aufnahme hätte zur Folge, dass
        // genau die Meldungen, um die es geht, für immer unlesbar bleiben.
        $this->actingAs($user)
            ->get(route('issues.show', $issue))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // „wird übersetzt" und nicht „nicht möglich": die Seite, die den
                // Auftrag ausgelöst hat, wüsste sonst als einzige nichts davon.
                ->where('event.symbolication.status', SymbolicationStatus::Pending->value)
            );

        Queue::assertPushedOn('symbolication', SymbolicateEvent::class);

        // Ein zweites Aufschlagen reiht nichts nachträglich ein: die Vormerkung
        // entscheidet, wer rechnet.
        $this->actingAs($user)->get(route('issues.show', $issue))->assertOk();

        Queue::assertPushed(SymbolicateEvent::class, 1);
    }
}
