<?php

namespace Tests\Feature\Ingest;

use App\Enums\GroupingSource;
use App\Jobs\ProcessIngestPayload;
use App\Models\Event;
use App\Models\EventGroup;
use App\Models\FingerprintRule;
use App\Models\IngestPayload;
use App\Models\Project;
use App\Support\Ingest\Processing\ProcessingPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Die Gruppierung im Zusammenspiel: von der angenommenen Meldung bis zur
 * Gruppe in der Datenbank.
 *
 * Der Unterschied zum Test der Gruppierung für sich (Tests\Unit\GroupingTest)
 * ist die Frage: dort, ob der Fingerabdruck stimmt — hier, ob aus vielen
 * Meldungen tatsächlich **eine** Gruppe wird und die Entscheidung
 * nachvollziehbar am Ereignis steht.
 */
class EventGroupingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Nimmt eine Meldung an und lässt sie durch die Kette laufen.
     *
     * @param  array<mixed>  $data
     */
    private function ingest(Project $project, array $data): Event
    {
        $eventId = IngestPayload::freshEventId();

        $payload = IngestPayload::factory()->create([
            'project_id' => $project->id,
            'event_id' => $eventId,
            'payload' => (string) json_encode($data + [
                'event_id' => $eventId,
                'timestamp' => now()->toIso8601String(),
                'platform' => 'php',
            ]),
        ]);

        ProcessIngestPayload::dispatch($payload);

        return Event::query()->where('ingest_payload_id', $payload->id)->sole();
    }

    /**
     * Eine Ausnahme mit Stacktrace, deren Text eine wechselnde Kennung trägt.
     *
     * @return array<mixed>
     */
    private function crash(string $value, string $type = 'RuntimeException', string $function = 'show'): array
    {
        return [
            'exception' => ['values' => [[
                'type' => $type,
                'value' => $value,
                'stacktrace' => ['frames' => [[
                    'filename' => 'app/Http/Controllers/InvoiceController.php',
                    'function' => $function,
                    'lineno' => 42,
                    'in_app' => true,
                ]]],
            ]]],
        ];
    }

    /**
     * Der Kern der Aufgabe, so wie die Testanleitung ihn beschreibt: derselbe
     * Fehler zwanzigmal mit wechselnder Nutzer-Nummer, ein anderer Fehler
     * einmal, ein dritter mit eigenem Fingerabdruck — drei Gruppen, die erste
     * mit zwanzig Ereignissen.
     */
    public function test_twenty_identical_crashes_become_one_group(): void
    {
        $project = Project::factory()->create();

        foreach (range(4711, 4730) as $userId) {
            $this->ingest($project, $this->crash("Nutzer {$userId} nicht gefunden"));
        }

        $this->ingest($project, $this->crash('Konto gesperrt', 'LogicException', 'store'));

        $this->ingest($project, $this->crash('Rechnung fehlgeschlagen') + ['fingerprint' => ['abrechnung']]);

        $this->assertSame(3, EventGroup::query()->count());

        $groups = EventGroup::query()->withCount('events')->get()->pluck('events_count')->sort()->values();

        $this->assertSame([1, 1, 20], $groups->all());
    }

    /**
     * Jedes Ereignis kennt seine Gruppe, und die Gruppe kennt ihr Verfahren.
     */
    public function test_every_event_carries_its_group_and_its_reasoning(): void
    {
        $project = Project::factory()->create();

        $event = $this->ingest($project, $this->crash('Nutzer 4711 nicht gefunden'));

        $this->assertNotNull($event->event_group_id);
        $this->assertNotNull($event->fingerprint);

        $grouping = $event->grouping;

        $this->assertSame(GroupingSource::Stacktrace->value, $grouping['source']);
        $this->assertNull($grouping['rule_id']);

        // Die Begründung nennt die Bestandteile, aus denen der Fingerabdruck
        // entstand — ohne sie wäre er eine Zeichenkette ohne Herkunft.
        $this->assertContains('error.type=RuntimeException', $grouping['values']);

        $group = $event->group()->sole();

        $this->assertSame(GroupingSource::Stacktrace, $group->source);
        $this->assertSame($event->fingerprint, $group->fingerprint);
    }

    /**
     * Dieselbe Meldung in zwei Projekten sind zwei Gruppen. Der Fingerabdruck
     * ist derselbe — die Projekte sind es nicht, und ihre Fehlerlisten dürfen
     * sich nicht vermischen.
     */
    public function test_the_same_error_in_another_project_is_another_group(): void
    {
        $here = Project::factory()->create();
        $there = Project::factory()->create();

        $first = $this->ingest($here, $this->crash('Nutzer 4711 nicht gefunden'));
        $second = $this->ingest($there, $this->crash('Nutzer 4711 nicht gefunden'));

        $this->assertSame($first->fingerprint, $second->fingerprint);
        $this->assertNotSame($first->event_group_id, $second->event_group_id);
        $this->assertSame(2, EventGroup::query()->count());
    }

    /**
     * Eine projektweite Regel greift und wird an Gruppe und Ereignis vermerkt.
     */
    public function test_a_project_rule_is_applied_and_recorded(): void
    {
        $project = Project::factory()->create();

        $rule = FingerprintRule::factory()->for($project)->rule(
            [['attribute' => 'error.type', 'pattern' => '*TimeoutException']],
            ['zeitueberschreitung'],
        )->create();

        $first = $this->ingest($project, $this->crash('Antwort blieb aus', 'HttpTimeoutException'));
        $second = $this->ingest($project, $this->crash('Verbindung abgebrochen', 'DbTimeoutException', 'query'));

        $this->assertSame($first->event_group_id, $second->event_group_id);
        $this->assertSame(GroupingSource::Rule->value, $first->grouping['source']);
        $this->assertSame($rule->id, $first->grouping['rule_id']);

        $group = $first->group()->sole();

        $this->assertSame($rule->id, $group->fingerprint_rule_id);
    }

    /**
     * Eine abgeschaltete Regel greift nicht — sie bleibt aber stehen.
     */
    public function test_an_inactive_rule_does_not_apply(): void
    {
        $project = Project::factory()->create();

        FingerprintRule::factory()->for($project)->inactive()->rule(
            [['attribute' => 'error.type', 'pattern' => '*']],
            ['alles-eins'],
        )->create();

        $event = $this->ingest($project, $this->crash('Nutzer 4711 nicht gefunden'));

        $this->assertSame(GroupingSource::Stacktrace->value, $event->grouping['source']);
    }

    /**
     * Eine gelöschte Regel nimmt ihre Gruppe nicht mit.
     *
     * Die Ereignisse liegen weiter darin; die Gruppe zu entfernen hieße, sie zu
     * verlieren — und das Löschen einer Regel ist eine Änderung an der Zukunft,
     * nicht an der Vergangenheit.
     */
    public function test_deleting_a_rule_keeps_its_group(): void
    {
        $project = Project::factory()->create();

        $rule = FingerprintRule::factory()->for($project)->rule(
            [['attribute' => 'error.type', 'pattern' => 'RuntimeException']],
            ['abrechnung'],
        )->create();

        $event = $this->ingest($project, $this->crash('kaputt'));

        $rule->delete();

        $group = $event->group()->sole();

        $this->assertNull($group->fingerprint_rule_id);
        $this->assertSame(1, $group->events()->count());
    }

    /**
     * Läuft dieselbe Meldung ein zweites Mal durch die Kette — nach einem
     * Fehlschlag, nach einer Verbesserung an einem Schritt —, entsteht keine
     * zweite Gruppe und kein zweites Ereignis.
     */
    public function test_processing_the_same_payload_twice_does_not_duplicate_the_group(): void
    {
        $project = Project::factory()->create();

        $eventId = IngestPayload::freshEventId();

        $payload = IngestPayload::factory()->create([
            'project_id' => $project->id,
            'event_id' => $eventId,
            'payload' => (string) json_encode($this->crash('kaputt') + [
                'event_id' => $eventId,
                'timestamp' => now()->toIso8601String(),
                'platform' => 'php',
            ]),
        ]);

        (new ProcessIngestPayload($payload))->handle(app(ProcessingPipeline::class));
        (new ProcessIngestPayload($payload))->handle(app(ProcessingPipeline::class));

        $this->assertSame(1, EventGroup::query()->count());
        $this->assertSame(1, Event::query()->count());
    }

    /**
     * Eine Meldung ohne Ausnahme und ohne Text bekommt trotzdem eine Gruppe —
     * die Kette bricht nicht ab und nichts geht verloren.
     */
    public function test_a_report_without_anything_to_group_by_still_gets_a_group(): void
    {
        $project = Project::factory()->create();

        $event = $this->ingest($project, []);

        $this->assertNotNull($event->event_group_id);
        $this->assertSame(GroupingSource::Empty->value, $event->grouping['source']);
    }
}
