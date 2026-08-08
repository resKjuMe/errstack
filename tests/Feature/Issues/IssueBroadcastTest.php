<?php

namespace Tests\Feature\Issues;

use App\Enums\IssueCategory;
use App\Enums\QueueName;
use App\Events\IssueCreated;
use App\Jobs\ProcessIngestPayload;
use App\Models\IngestPayload;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Die Live-Aktualisierung der Fehlerliste: gemeldet wird das **erste**
 * Auftreten eines Fehlers und sonst nichts.
 *
 * Der zweite Teil ist der wichtigere. Eine Meldung je Ereignis wäre bei einem
 * Ausfall — dem Fall, für den diese Anwendung gebaut ist — tausend Broadcasts in
 * der Minute für denselben Eintrag, und die Ansicht täte nichts anderes mehr,
 * als eine Zahl hochzuzählen. Dass es hier still bleibt, ist deshalb keine
 * Nebensache, sondern die eigentliche Zusage.
 */
class IssueBroadcastTest extends TestCase
{
    use RefreshDatabase;

    private function project(): Project
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user)->create();

        return Project::factory()->for($organization)->create();
    }

    /**
     * Nimmt dieselbe Meldung an und lässt sie durch die Kette laufen.
     */
    private function ingest(
        Project $project,
        string $value = 'Rechnung fehlgeschlagen',
        string $type = 'RuntimeException',
        string $function = 'store',
    ): void {
        $eventId = IngestPayload::freshEventId();

        $payload = IngestPayload::factory()->create([
            'project_id' => $project->id,
            'event_id' => $eventId,
            'payload' => (string) json_encode([
                'event_id' => $eventId,
                'timestamp' => Carbon::now()->toIso8601String(),
                'platform' => 'php',
                'exception' => ['values' => [[
                    'type' => $type,
                    'value' => $value,
                    'stacktrace' => ['frames' => [[
                        'filename' => 'app/Http/Controllers/InvoiceController.php',
                        'function' => $function,
                        'lineno' => 42,
                    ]]],
                ]]],
            ]),
        ]);

        ProcessIngestPayload::dispatch($payload);
    }

    public function test_a_new_issue_is_announced_once(): void
    {
        Event::fake([IssueCreated::class]);

        $project = $this->project();

        $this->ingest($project);

        Event::assertDispatched(IssueCreated::class, function (IssueCreated $event) use ($project): bool {
            return $event->projectId === $project->id
                && $event->organizationId === $project->organization_id
                && str_contains($event->title, 'Rechnung fehlgeschlagen');
        });
        Event::assertDispatchedTimes(IssueCreated::class, 1);
    }

    public function test_a_known_issue_stays_quiet(): void
    {
        Event::fake([IssueCreated::class]);

        $project = $this->project();

        $this->ingest($project);
        $this->ingest($project);
        $this->ingest($project);

        // Drei Meldungen, ein Eintrag, eine Ankündigung. Die beiden weiteren
        // Auftreten stehen in den Zählern und brauchen keinen Broadcast.
        Event::assertDispatchedTimes(IssueCreated::class, 1);
    }

    public function test_a_second_issue_gets_its_own_announcement(): void
    {
        Event::fake([IssueCreated::class]);

        $project = $this->project();

        // Ein anderer Fehler heißt: eine andere Gruppe. Nur einen anderen
        // Fehlertext zu schicken genügt dafür nicht — die Gruppierung (I5) fasst
        // dieselbe Stelle mit derselben Ausnahme bewusst zusammen, sonst wäre
        // jede Meldung mit einer Kennung darin ein eigener Eintrag.
        $this->ingest($project, 'Rechnung fehlgeschlagen');
        $this->ingest($project, 'Zahlung abgelehnt', 'DomainException', 'capture');

        Event::assertDispatchedTimes(IssueCreated::class, 2);
    }

    public function test_the_announcement_runs_on_one_private_organization_channel_and_off_the_ingest_queue(): void
    {
        $project = $this->project();
        $event = new IssueCreated(
            7,
            $project->organization_id,
            $project->id,
            IssueCategory::Error->value,
            'RuntimeException',
            'error',
            Carbon::now()->toIso8601String(),
        );

        // Ein Kanal für die ganze Organisation, nicht einer je Projekt: sonst
        // wären es bei fünfzig Projekten fünfzig Abos für einen Seitenaufruf.
        // `PrivateChannel` stellt „private-" voran — genau daran erkennt der
        // Websocket-Server, dass er nachfragen muss.
        $this->assertSame(
            'private-organizations.'.$project->organization_id.'.issues',
            (string) $event->broadcastOn(),
        );

        // Das Projekt fährt in der Nutzlast mit, damit die Ansicht auf ihre
        // Auswahl filtern kann.
        $this->assertSame($project->id, $event->broadcastWith()['projectId']);

        // Und die Kategorie ebenso: derselbe Kanal trägt seit PF6 auch die
        // Leistungsprobleme, und die Fehlerliste muss sie aussortieren können.
        $this->assertSame(IssueCategory::Error->value, $event->broadcastWith()['category']);

        // Die Aufnahme darf nicht darauf warten, dass ein Websocket-Server
        // antwortet.
        $this->assertSame(QueueName::Notifications->value, $event->broadcastQueue());
    }
}
