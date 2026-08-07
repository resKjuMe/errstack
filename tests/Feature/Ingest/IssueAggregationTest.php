<?php

namespace Tests\Feature\Ingest;

use App\Enums\CountPeriod;
use App\Enums\EventLevel;
use App\Enums\IssuePriority;
use App\Enums\IssueStatus;
use App\Jobs\ProcessIngestPayload;
use App\Models\Event;
use App\Models\EventGroup;
use App\Models\IngestPayload;
use App\Models\Issue;
use App\Models\IssueCount;
use App\Models\IssueUser;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Die Aggregation im Zusammenspiel: von der angenommenen Meldung bis zu den
 * Zahlen, die am Fehler-Eintrag stehen.
 *
 * Der Unterschied zum Test der Gruppierung (Tests\Feature\Ingest\EventGroupingTest)
 * ist die Frage: dort, ob aus vielen Meldungen **eine Gruppe** wird — hier, ob
 * die Zahlen daneben stimmen. Sie sind die einzige Quelle für Alarm-Bedingungen
 * und Diagramme; ein Zähler, der um eins danebenliegt, ist deshalb kein
 * Schönheitsfehler, sondern ein falscher Alarm oder ein ausgebliebener.
 */
class IssueAggregationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Nimmt eine Meldung an und lässt sie durch die Kette laufen.
     *
     * @param  array<mixed>  $data
     */
    private function ingest(Project $project, array $data, ?Carbon $at = null): Event
    {
        $eventId = IngestPayload::freshEventId();

        $payload = IngestPayload::factory()->create([
            'project_id' => $project->id,
            'event_id' => $eventId,
            'payload' => (string) json_encode($data + [
                'event_id' => $eventId,
                'timestamp' => ($at ?? now())->toIso8601String(),
                'platform' => 'php',
            ]),
        ]);

        ProcessIngestPayload::dispatch($payload);

        return Event::query()->where('ingest_payload_id', $payload->id)->sole();
    }

    /**
     * Eine Ausnahme mit Stacktrace — derselbe Fehler, wechselnder Nutzer.
     *
     * Der Fehlertext bleibt gleich, damit alle Meldungen in eine Gruppe fallen;
     * unterschiedlich ist nur, wen es getroffen hat.
     *
     * @return array<mixed>
     */
    private function crash(?string $userId = null, string $type = 'RuntimeException'): array
    {
        $data = [
            'exception' => ['values' => [[
                'type' => $type,
                'value' => 'Rechnung konnte nicht erstellt werden',
                'stacktrace' => ['frames' => [[
                    'filename' => 'app/Http/Controllers/InvoiceController.php',
                    'function' => 'store',
                    'lineno' => 42,
                    'in_app' => true,
                ]]],
            ]]],
        ];

        return $userId === null ? $data : $data + ['user' => ['id' => $userId]];
    }

    /**
     * Der Kern der Aufgabe, so wie die Testanleitung ihn beschreibt: derselbe
     * Fehler mit drei Nutzer-Nummern je fünfmal — fünfzehn Ereignisse, drei
     * Betroffene, dazu passendes erstes und letztes Auftreten.
     */
    public function test_fifteen_events_of_three_users_become_one_issue(): void
    {
        $project = Project::factory()->create();

        $first = Carbon::parse('2026-08-01 09:00:00');

        foreach (range(0, 4) as $round) {
            foreach (['4711', '4712', '4713'] as $index => $userId) {
                $this->ingest(
                    $project,
                    $this->crash($userId),
                    $first->copy()->addMinutes($round * 10 + $index),
                );
            }
        }

        $issue = Issue::query()->sole();

        $this->assertSame(15, $issue->times_seen);
        $this->assertSame(3, $issue->users_seen);
        $this->assertSame($first->toDateTimeString(), $issue->first_seen->toDateTimeString());
        $this->assertSame(
            $first->copy()->addMinutes(42)->toDateTimeString(),
            $issue->last_seen->toDateTimeString(),
        );

        // Ein Eintrag zu einer Gruppe — und die Gruppe weiß es auch.
        $this->assertSame(1, EventGroup::query()->count());
        $this->assertSame($issue->id, EventGroup::query()->sole()->issue_id);
    }

    /**
     * Der Eintrag übernimmt, was in der Liste steht: Titel, Fehlerstelle, Art,
     * Grad — und beginnt offen und ohne Bewertung.
     */
    public function test_a_new_issue_describes_the_error_and_starts_open(): void
    {
        $project = Project::factory()->create();

        $event = $this->ingest($project, $this->crash('4711'));

        $issue = Issue::query()->sole();

        $this->assertSame($event->title, $issue->title);
        $this->assertSame($event->culprit, $issue->culprit);
        $this->assertSame('RuntimeException', $issue->type);
        $this->assertSame(EventLevel::Error, $issue->level);
        $this->assertSame(IssueStatus::Unresolved, $issue->status);
        $this->assertSame(IssuePriority::Medium, $issue->priority);
    }

    /**
     * Zwei verschiedene Fehler sind zwei Einträge — und zwar auch dann, wenn sie
     * im selben Projekt und in derselben Minute auftreten.
     */
    public function test_two_different_errors_become_two_issues(): void
    {
        $project = Project::factory()->create();

        $this->ingest($project, $this->crash('4711'));
        $this->ingest($project, $this->crash('4711', 'LogicException'));
        $this->ingest($project, $this->crash('4712', 'LogicException'));

        $this->assertSame(2, Issue::query()->count());

        $counts = Issue::query()->orderBy('id')->pluck('times_seen')->all();

        $this->assertSame([1, 2], $counts);
    }

    /**
     * Ein zweiter Durchlauf derselben Meldung zählt nicht noch einmal.
     *
     * Der Fall ist eingeplant und nicht ausgedacht: nach einem Fehlschlag läuft
     * der Job erneut, und nach einer Verbesserung an einem Schritt sollen sich
     * alte Meldungen neu auswerten lassen. Der ausgewertete Datensatz wird dann
     * ersetzt — der Zähler würde ohne den Vermerk am Ereignis nur wachsen.
     */
    public function test_processing_the_same_payload_twice_counts_once(): void
    {
        $project = Project::factory()->create();

        $event = $this->ingest($project, $this->crash('4711'));

        ProcessIngestPayload::dispatch($event->payload()->sole());

        $issue = Issue::query()->sole();

        $this->assertSame(1, $issue->times_seen);
        $this->assertSame(1, $issue->users_seen);
        $this->assertSame(1, Event::query()->count());
    }

    /**
     * Der Verlauf: je Stunde und je Tag ein Zähler.
     *
     * Beide Auflösungen, weil sie verschiedene Fragen beantworten — die Stunde
     * die nach dem Ausschlag von heute Mittag, der Tag die nach dem Verlauf über
     * ein Vierteljahr.
     */
    public function test_counters_are_kept_per_hour_and_per_day(): void
    {
        $project = Project::factory()->create();

        $day = Carbon::parse('2026-08-01 10:15:00');

        $this->ingest($project, $this->crash('4711'), $day);
        $this->ingest($project, $this->crash('4711'), $day->copy()->addMinutes(30));
        $this->ingest($project, $this->crash('4711'), $day->copy()->addHour());
        $this->ingest($project, $this->crash('4711'), $day->copy()->addDay());

        $issue = Issue::query()->sole();

        $hours = IssueCount::query()
            ->where('issue_id', $issue->id)
            ->where('period', CountPeriod::Hour)
            ->orderBy('window_start')
            ->get();

        $this->assertSame(3, $hours->count());
        $this->assertSame([2, 1, 1], $hours->pluck('event_count')->all());

        // Abgeschnitten und nicht gerundet: 10:15 gehört in die Stunde 10.
        $this->assertSame('2026-08-01 10:00:00', $hours->first()?->window_start->toDateTimeString());

        $days = IssueCount::query()
            ->where('issue_id', $issue->id)
            ->where('period', CountPeriod::Day)
            ->orderBy('window_start')
            ->get();

        $this->assertSame(2, $days->count());
        $this->assertSame([3, 1], $days->pluck('event_count')->all());
        $this->assertSame('2026-08-01 00:00:00', $days->first()?->window_start->toDateTimeString());
    }

    /**
     * Eine nachgereichte alte Meldung datiert den Eintrag nicht zurück.
     *
     * Meldungen kommen nicht in ihrer zeitlichen Reihenfolge an: ein SDK, das
     * nach einer Netztrennung seine Warteschlange leert, liefert Stunden später
     * Ereignisse von vorhin. Das letzte Auftreten muss dann stehen bleiben — und
     * das erste zurückgehen.
     */
    public function test_a_late_arrival_moves_first_seen_but_not_last_seen(): void
    {
        $project = Project::factory()->create();

        $now = Carbon::parse('2026-08-01 12:00:00');

        $this->ingest($project, $this->crash('4711'), $now);
        $this->ingest($project, $this->crash('4711'), $now->copy()->subHours(3));

        $issue = Issue::query()->sole();

        $this->assertSame(2, $issue->times_seen);
        $this->assertSame($now->copy()->subHours(3)->toDateTimeString(), $issue->first_seen->toDateTimeString());
        $this->assertSame($now->toDateTimeString(), $issue->last_seen->toDateTimeString());

        // Der Zähler des alten Fensters steht trotzdem am richtigen Platz.
        $this->assertSame(2, IssueCount::query()->where('period', CountPeriod::Hour)->count());
    }

    /**
     * Der Grad folgt dem jüngsten Ereignis — ein nachgereichtes altes dreht ihn
     * nicht zurück.
     *
     * An ihm hängen die Alarmregeln: ein Fehler, der von `warning` auf `fatal`
     * gewechselt ist, ist ab dann ein anderer Fall.
     */
    public function test_the_level_follows_the_newest_event(): void
    {
        $project = Project::factory()->create();

        $now = Carbon::parse('2026-08-01 12:00:00');

        $this->ingest($project, $this->crash('4711') + ['level' => 'warning'], $now);
        $this->ingest($project, $this->crash('4711') + ['level' => 'fatal'], $now->copy()->addMinute());
        $this->ingest($project, $this->crash('4711') + ['level' => 'info'], $now->copy()->subHour());

        $this->assertSame(EventLevel::Fatal, Issue::query()->sole()->level);
    }

    /**
     * Derselbe Nutzer zählt einmal, verschiedene Kennungen zählen einzeln — und
     * eine Meldung ohne jede Angabe zählt gar nicht.
     *
     * Der letzte Teil ist der wichtige: eine anonyme Meldung als „ein
     * Betroffener" zu führen wäre eine erfundene Zahl an einer Stelle, an der
     * eine echte erwartet wird.
     */
    public function test_users_are_counted_once_and_only_when_known(): void
    {
        $project = Project::factory()->create();

        $this->ingest($project, $this->crash('4711'));
        $this->ingest($project, $this->crash('4711'));
        $this->ingest($project, $this->crash('4712'));
        $this->ingest($project, $this->crash());

        $issue = Issue::query()->sole();

        $this->assertSame(4, $issue->times_seen);
        $this->assertSame(2, $issue->users_seen);
        $this->assertSame(2, $issue->affectedUsers()->count());
    }

    /**
     * Zwei Arbeiter zählen nebeneinander, ohne sich zu überschreiben.
     *
     * Nachgestellt wird die Lage, in der das „verlorene Hochzählen" entsteht:
     * beide haben den Eintrag gelesen, **bevor** der andere gezählt hat. Wer im
     * Speicher rechnet und zurückschreibt, kommt hier auf eins. Wer die
     * Datenbank rechnen lässt, auf zwei.
     */
    public function test_two_stale_instances_do_not_lose_an_increment(): void
    {
        $project = Project::factory()->create();

        $first = $this->ingest($project, $this->crash('4711'));
        $second = $this->ingest($project, $this->crash('4712'));

        // Beide Ereignisse noch einmal zählbar machen — sie sind durch die Kette
        // gelaufen und tragen deshalb schon ihren Vermerk.
        Event::query()->update(['counted_at' => null]);
        Issue::query()->update(['times_seen' => 0, 'users_seen' => 0]);
        IssueUser::query()->delete();

        $issue = Issue::query()->sole();
        $stale = Issue::query()->sole();

        $this->assertTrue($issue->record($first->refresh()));
        $this->assertTrue($stale->record($second->refresh()));

        $fresh = Issue::query()->sole();

        $this->assertSame(2, $fresh->times_seen);
        $this->assertSame(2, $fresh->users_seen);
    }

    /**
     * Ein bereits gezähltes Ereignis wird nicht noch einmal genommen — auch dann
     * nicht, wenn jemand die Aggregation von Hand aufruft.
     */
    public function test_recording_an_event_twice_is_refused(): void
    {
        $project = Project::factory()->create();

        $event = $this->ingest($project, $this->crash('4711'));

        $issue = Issue::query()->sole();

        $this->assertFalse($issue->record($event));
        $this->assertSame(1, Issue::query()->sole()->times_seen);
    }

    /**
     * Dieselbe Meldung in zwei Projekten sind zwei Einträge. Ihre Fehlerlisten
     * dürfen sich nicht vermischen.
     */
    public function test_the_same_error_in_another_project_is_another_issue(): void
    {
        $here = Project::factory()->create();
        $there = Project::factory()->create();

        $this->ingest($here, $this->crash('4711'));
        $this->ingest($there, $this->crash('4711'));

        $this->assertSame(2, Issue::query()->count());
        $this->assertSame(1, Issue::query()->where('project_id', $here->id)->sole()->times_seen);
        $this->assertSame(1, Issue::query()->where('project_id', $there->id)->sole()->times_seen);
    }

    /**
     * Die Zähler halten der Last stand, die die Aufgabe nennt: 1.000 Ereignisse
     * in einer Minute, alle in denselben Eintrag.
     *
     * Der Test läuft nacheinander und beweist damit keine Nebenläufigkeit — die
     * beweist {@see test_two_stale_instances_do_not_lose_an_increment()}. Er
     * beweist etwas anderes, das genauso nötig ist: dass eine hohe Zahl von
     * Ereignissen in **einer** Zeile keine Sonderbehandlung braucht und nichts
     * überläuft oder verrutscht.
     */
    public function test_a_thousand_events_in_one_minute_stay_correct(): void
    {
        $project = Project::factory()->create();

        $group = EventGroup::factory()->for($project)->create();
        $at = Carbon::parse('2026-08-01 12:00:00');

        $issue = Issue::forGroup($group, Event::factory()->for($project)->for($group, 'group')->make([
            'occurred_at' => $at,
        ]));

        foreach (range(1, 1000) as $index) {
            $event = Event::factory()->for($project)->for($group, 'group')->create([
                'occurred_at' => $at->copy()->addMilliseconds($index * 50),
                'user' => ['id' => (string) ($index % 25)],
            ]);

            $issue->record($event);
        }

        $fresh = $issue->fresh();

        $this->assertNotNull($fresh);
        $this->assertSame(1000, $fresh->times_seen);
        $this->assertSame(25, $fresh->users_seen);

        $this->assertSame(
            1000,
            (int) IssueCount::query()
                ->where('issue_id', $issue->id)
                ->where('period', CountPeriod::Hour)
                ->sum('event_count'),
        );
    }
}
