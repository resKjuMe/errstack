<?php

namespace Tests\Feature\Ingest;

use App\Enums\IssueActivityType;
use App\Enums\IssueAlertCondition;
use App\Enums\IssueResolveMode;
use App\Enums\IssueStatus;
use App\Jobs\ProcessIngestPayload;
use App\Models\Event;
use App\Models\IngestPayload;
use App\Models\Issue;
use App\Models\IssueActivity;
use App\Models\IssueAlertRule;
use App\Models\IssueAlertTrigger;
use App\Models\Project;
use App\Models\ProjectKey;
use App\Models\Release;
use App\Models\User;
use App\Support\Issues\IssueActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Die Rückkehr-Erkennung (S8) in der Kette.
 *
 * Sie lässt sich nirgends sonst prüfen: ob ein erledigter Fehler bei seiner
 * Rückkehr wieder aufgeht, entscheidet sich zwischen dem Zählen und der
 * Alarmierung, und dort ist der Fehler teuer in beide Richtungen — ein Eintrag,
 * der zu früh aufgeht, macht jede Erledigung von der nächsten nachgereichten
 * Altmeldung zunichte; einer, der nicht aufgeht, verschweigt einen Fehler, den
 * jemand für behoben hielt.
 */
class IssueRegressionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ein Schlüssel je Projekt, wiederverwendet — wie in der Nachbarprüfung:
     * zwei Schlüssel im selben Test teilen die Zählungen der Aufnahme auf.
     *
     * @var array<int, ProjectKey>
     */
    private array $keys = [];

    /**
     * Nimmt eine Meldung an und lässt sie durch die Kette laufen.
     *
     * @param  array<mixed>  $data
     */
    private function ingest(Project $project, array $data = [], ?Carbon $at = null): Event
    {
        $eventId = IngestPayload::freshEventId();

        $key = $this->keys[$project->id] ??= ProjectKey::factory()->for($project)->create();

        $payload = IngestPayload::factory()->create([
            'project_id' => $project->id,
            'project_key_id' => $key->id,
            'event_id' => $eventId,
            'payload' => (string) json_encode($this->crash() + $data + [
                'event_id' => $eventId,
                'timestamp' => ($at ?? now())->toIso8601String(),
                'platform' => 'php',
            ]),
        ]);

        ProcessIngestPayload::dispatch($payload);

        return Event::query()->where('ingest_payload_id', $payload->id)->sole();
    }

    /**
     * Immer derselbe Fehler — sonst entstünde je Meldung ein eigener Eintrag.
     *
     * @return array<mixed>
     */
    private function crash(): array
    {
        return [
            'exception' => ['values' => [[
                'type' => 'RuntimeException',
                'value' => 'Rechnung konnte nicht erstellt werden',
                'stacktrace' => ['frames' => [[
                    'filename' => 'app/Http/Controllers/InvoiceController.php',
                    'function' => 'store',
                    'lineno' => 42,
                    'in_app' => true,
                ]]],
            ]]],
        ];
    }

    private function resolve(Issue $issue, IssueResolveMode $mode, ?User $actor = null): void
    {
        (new IssueActions($actor))->resolve(Issue::query()->whereKey($issue->id), $mode);
    }

    /**
     * Der Fall aus der Testanleitung: senden, erledigen, erneut senden.
     */
    public function test_a_resolved_issue_reopens_when_it_occurs_again(): void
    {
        $project = Project::factory()->create();
        $actor = User::factory()->create();

        $this->ingest($project);

        $issue = Issue::query()->sole();
        $this->resolve($issue, IssueResolveMode::Now, $actor);

        Carbon::setTestNow(now()->addMinutes(5));

        $this->ingest($project);

        $issue->refresh();

        $this->assertSame(IssueStatus::Unresolved, $issue->status);
        $this->assertNotNull($issue->regressed_at);

        // Die Erledigung ist zurückgenommen und nicht bloß überschrieben:
        // stünde sie noch da, gälte der Eintrag beim nächsten Ereignis erneut
        // als Rückfall.
        $this->assertNull($issue->resolved_at);
        $this->assertNull($issue->resolved_by_id);

        $activity = IssueActivity::query()
            ->where('issue_id', $issue->id)
            ->where('type', IssueActivityType::Regressed)
            ->sole();

        // Ein Rückfall geschieht, er wird nicht getan — der Vermerk trägt
        // deshalb keinen Namen.
        $this->assertNull($activity->user_id);
        $this->assertNull($activity->actor_name);
    }

    /**
     * Was an dem Eintrag hängt, gehört weiter zu ihm: ein Rückfall ist derselbe
     * Fehler und kein neuer.
     */
    public function test_a_regression_keeps_the_counters_and_the_subscribers(): void
    {
        $project = Project::factory()->create();
        $watcher = User::factory()->create();

        $this->ingest($project);

        $issue = Issue::query()->sole();
        $issue->subscribers()->attach($watcher->id);

        $this->resolve($issue, IssueResolveMode::Now);

        Carbon::setTestNow(now()->addMinutes(5));

        $this->ingest($project);

        $issue->refresh();

        $this->assertSame(2, $issue->times_seen);
        $this->assertSame([$watcher->id], $issue->watcherIds());
    }

    /**
     * Nachgereichte Altmeldungen lösen keinen Rückfall aus.
     */
    public function test_an_event_from_before_the_resolution_does_not_reopen(): void
    {
        $project = Project::factory()->create();

        $this->ingest($project);

        $issue = Issue::query()->sole();
        $this->resolve($issue, IssueResolveMode::Now);

        $resolvedAt = $issue->refresh()->resolved_at;

        Carbon::setTestNow(now()->addHour());

        // Entstanden ist die Meldung vor dem Erledigen, angekommen ist sie
        // danach — genau der Fall, den ein SDK nach einer Netztrennung liefert.
        $this->ingest($project, at: Carbon::instance($resolvedAt->subMinutes(10)->toDateTime()));

        $issue->refresh();

        $this->assertSame(IssueStatus::Resolved, $issue->status);
        $this->assertNull($issue->regressed_at);
    }

    /**
     * „Erledigt in dieser Version": Meldungen aus derselben Fassung sind der
     * Stand ohne den Fix, der noch läuft — kein Widerspruch.
     */
    public function test_the_same_release_does_not_reopen_a_release_bound_resolution(): void
    {
        $project = Project::factory()->create();

        $this->ingest($project, ['release' => '1.4.2']);

        $issue = Issue::query()->sole();
        $this->resolve($issue, IssueResolveMode::CurrentRelease);

        $this->assertNotNull($issue->refresh()->resolved_in_release_id);

        Carbon::setTestNow(now()->addMinutes(5));

        $this->ingest($project, ['release' => '1.4.2']);

        $this->assertSame(IssueStatus::Resolved, $issue->refresh()->status);
    }

    public function test_a_newer_release_reopens_a_release_bound_resolution(): void
    {
        $project = Project::factory()->create();

        $this->ingest($project, ['release' => '1.9.0']);

        $issue = Issue::query()->sole();
        $this->resolve($issue, IssueResolveMode::CurrentRelease);

        Carbon::setTestNow(now()->addMinutes(5));

        // Als Text sortiert stünde 1.10.0 vor 1.9.0 — die Rangfolge kommt aus
        // den Sortierfeldern der Auslieferung und nicht aus dem Vergleich der
        // Zeichenketten.
        $this->ingest($project, ['release' => '1.10.0']);

        $issue->refresh();

        $this->assertSame(IssueStatus::Unresolved, $issue->status);
        $this->assertNotNull($issue->regressed_at);

        // Die Version steht am Eintrag und im Verlauf: „ist wieder aufgetreten"
        // ohne „wo" ist die halbe Auskunft.
        $this->assertSame(
            '1.10.0',
            Release::query()->whereKey($issue->regressed_in_release_id)->value('version'),
        );

        $activity = IssueActivity::query()
            ->where('issue_id', $issue->id)
            ->where('type', IssueActivityType::Regressed)
            ->sole();

        $this->assertSame('1.10.0', $activity->data['release'] ?? null);
    }

    /**
     * „Erledigt mit der nächsten Auslieferung" hält bis zur nächsten
     * Auslieferung — und nur bis dahin.
     */
    public function test_resolved_in_next_release_holds_until_a_newer_release_arrives(): void
    {
        $project = Project::factory()->create();

        $this->ingest($project, ['release' => '2.0.0']);

        $issue = Issue::query()->sole();
        $this->resolve($issue, IssueResolveMode::NextRelease);

        Carbon::setTestNow(now()->addMinutes(5));

        $this->ingest($project, ['release' => '2.0.0']);

        $this->assertSame(IssueStatus::Resolved, $issue->refresh()->status);

        Carbon::setTestNow(now()->addMinutes(10));

        $this->ingest($project, ['release' => '2.1.0']);

        $this->assertSame(IssueStatus::Unresolved, $issue->refresh()->status);
    }

    /**
     * Der Rückfall wird einmal vermerkt und nicht bei jedem folgenden Ereignis
     * erneut: der Eintrag ist danach offen wie jeder andere offene auch.
     */
    public function test_a_regression_is_noted_once(): void
    {
        $project = Project::factory()->create();

        $this->ingest($project);

        $issue = Issue::query()->sole();
        $this->resolve($issue, IssueResolveMode::Now);

        Carbon::setTestNow(now()->addMinutes(5));

        $this->ingest($project);
        $this->ingest($project);
        $this->ingest($project);

        $this->assertSame(1, IssueActivity::query()
            ->where('issue_id', $issue->id)
            ->where('type', IssueActivityType::Regressed)
            ->count());
    }

    /**
     * Unzerlegbare Fassungen — ein Commit-Hash — haben keine Nummer; dann
     * entscheidet, wann die erste Meldung daraus eintraf.
     *
     * Der Fall hängt daran, dass die frisch angelegte Auslieferung ihren ersten
     * Zeitpunkt auch in der weitergereichten Instanz trägt
     * ({@see Release::noteEvent()}) — ohne ihn stünde dort `null`, und ein
     * Projekt, das Commit-Hashes als Version schickt, bekäme nie einen Rückfall
     * gemeldet.
     */
    public function test_an_unordered_later_release_reopens(): void
    {
        $project = Project::factory()->create();

        $this->ingest($project, ['release' => 'a1b2c3d']);

        $issue = Issue::query()->sole();
        $this->resolve($issue, IssueResolveMode::CurrentRelease);

        Carbon::setTestNow(now()->addHour());

        $this->ingest($project, ['release' => 'e4f5a6b']);

        $this->assertSame(IssueStatus::Unresolved, $issue->refresh()->status);
    }

    /**
     * Die Alarm-Regel „ein erledigter Fehler tritt wieder auf" sieht den
     * Rückfall — obwohl der Schritt davor den Eintrag längst aufgemacht hat.
     *
     * Das ist die Stelle, an der S8 die Alarmierung (A2) hätte blind machen
     * können: die Regel las bisher am Eintrag ab, dass er erledigt ist, und
     * genau das stimmt nach dem Aufmachen nicht mehr. Die Feststellung wandert
     * deshalb im Kontext mit
     * ({@see App\Support\Ingest\Processing\Steps\DetectRegression::RESOLVED_AT}).
     */
    public function test_the_regression_alert_still_fires_after_the_issue_reopened(): void
    {
        $project = Project::factory()->create();

        IssueAlertRule::factory()->for($project)->conditions([
            ['type' => IssueAlertCondition::Regression->value],
        ])->create();

        $this->ingest($project);

        $issue = Issue::query()->sole();

        $this->assertSame(0, IssueAlertTrigger::query()->count());

        $this->resolve($issue, IssueResolveMode::Now);

        Carbon::setTestNow(now()->addMinutes(5));

        $this->ingest($project);

        $this->assertSame(IssueStatus::Unresolved, $issue->refresh()->status);

        $trigger = IssueAlertTrigger::query()->sole();

        $this->assertSame([IssueAlertCondition::Regression->value], $trigger->conditions);
    }

    /**
     * Die Marke endet mit der nächsten Entscheidung von Hand — sonst stünde der
     * Eintrag für immer in der Ansicht „Wieder aufgetreten".
     */
    public function test_resolving_again_clears_the_regression_mark(): void
    {
        $project = Project::factory()->create();

        $this->ingest($project);

        $issue = Issue::query()->sole();
        $this->resolve($issue, IssueResolveMode::Now);

        Carbon::setTestNow(now()->addMinutes(5));

        $this->ingest($project);

        $this->assertNotNull($issue->refresh()->regressed_at);

        $this->resolve($issue, IssueResolveMode::Now);

        $this->assertNull($issue->refresh()->regressed_at);
    }
}
