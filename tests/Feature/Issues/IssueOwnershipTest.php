<?php

namespace Tests\Feature\Issues;

use App\Enums\OrganizationRole;
use App\Enums\OwnershipMatcher;
use App\Jobs\ProcessIngestPayload;
use App\Models\Event;
use App\Models\IngestPayload;
use App\Models\Issue;
use App\Models\Organization;
use App\Models\OwnershipRule;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Die Zuständigkeits-Regeln im Zusammenspiel: von der eintreffenden Meldung bis
 * zu dem Namen, der am Fehler steht — und zu dem, der im Zuweisungs-Dialog
 * vorgeschlagen wird.
 *
 * Der Unterschied zum Test der Regelpflege
 * ({@see Tests\Feature\Projects\OwnershipRuleTest}) ist die Frage: dort, ob sich
 * eine Regel schreiben lässt — hier, ob sie etwas bewirkt. Beide Hälften sind
 * nötig, weil zwischen ihnen die Auswertung der Meldung liegt: eine Regel kann
 * makellos gespeichert sein und trotzdem nie zutreffen, weil im Stacktrace ein
 * anderer Pfad steht, als jemand erwartet hat.
 */
class IssueOwnershipTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{User, Organization, Project}
     */
    private function project(bool $autoAssign = true): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user, OrganizationRole::Owner)->create();
        $project = Project::factory()->for($organization)->create([
            'name' => 'Webshop',
            'slug' => 'webshop',
            'ownership_auto_assign' => $autoAssign,
        ]);

        return [$user, $organization, $project];
    }

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
     * Ein Absturz in der Abrechnung — genau der Fall aus der Aufgabe.
     *
     * @return array<mixed>
     */
    private function crash(string $file = 'src/billing/Invoice.php', ?string $url = null): array
    {
        $data = [
            'exception' => ['values' => [[
                'type' => 'RuntimeException',
                'value' => 'Rechnung konnte nicht erstellt werden',
                'stacktrace' => ['frames' => [[
                    'filename' => $file,
                    'function' => 'store',
                    'lineno' => 42,
                    'in_app' => true,
                ]]],
            ]]],
        ];

        return $url === null ? $data : $data + ['request' => ['url' => $url]];
    }

    public function test_a_new_error_is_assigned_to_the_team_of_the_matching_rule(): void
    {
        [, $organization, $project] = $this->project();
        $team = Team::factory()->for($organization)->create(['name' => 'Kasse']);

        OwnershipRule::factory()->for($project)
            ->matching(OwnershipMatcher::Path, 'src/billing/*', ['#Kasse'])->create();

        $this->ingest($project, $this->crash());

        $issue = Issue::query()->sole();

        $this->assertSame($team->id, $issue->assigned_team_id);
        $this->assertNull($issue->assigned_user_id);
        // Zugewiesen heißt geprüft: der Eintrag verlässt damit die Prüfliste.
        $this->assertNull($issue->for_review_at);
    }

    public function test_the_last_matching_rule_decides(): void
    {
        [, $organization, $project] = $this->project();
        Team::factory()->for($organization)->create(['name' => 'Plattform']);
        $kasse = Team::factory()->for($organization)->create(['name' => 'Kasse']);

        OwnershipRule::factory()->for($project)->at(0)
            ->matching(OwnershipMatcher::Path, 'src/*', ['#Plattform'])->create();
        OwnershipRule::factory()->for($project)->at(1)
            ->matching(OwnershipMatcher::Path, 'src/billing/*', ['#Kasse'])->create();

        $this->ingest($project, $this->crash());

        $this->assertSame($kasse->id, Issue::query()->sole()->assigned_team_id);
    }

    public function test_a_url_rule_reaches_an_error_without_a_useful_path(): void
    {
        [, $organization, $project] = $this->project();
        $team = Team::factory()->for($organization)->create(['name' => 'Kasse']);

        OwnershipRule::factory()->for($project)
            ->matching(OwnershipMatcher::Url, '*/checkout/*', ['#Kasse'])->create();

        $this->ingest($project, $this->crash(
            file: 'https://cdn.example.com/build/app.9f2c.js',
            url: 'https://example.com/checkout/summe',
        ));

        $this->assertSame($team->id, Issue::query()->sole()->assigned_team_id);
    }

    /**
     * Der Schalter ist die ganze Zusage: aus heißt aus, und die Regeln bleiben
     * ein Vorschlag.
     */
    public function test_nothing_is_assigned_while_the_switch_is_off(): void
    {
        [, $organization, $project] = $this->project(autoAssign: false);
        Team::factory()->for($organization)->create(['name' => 'Kasse']);

        OwnershipRule::factory()->for($project)
            ->matching(OwnershipMatcher::Path, 'src/billing/*', ['#Kasse'])->create();

        $this->ingest($project, $this->crash());

        $this->assertNull(Issue::query()->sole()->assigned_team_id);
    }

    /**
     * Die wichtigste Zusage des Schalters: eine von Hand gesetzte Zuständigkeit
     * wird nie überschrieben. Geprüft am zweiten Auftreten desselben Fehlers —
     * dem einzigen Zeitpunkt, an dem der Schritt einen bereits zugewiesenen
     * Eintrag überhaupt zu sehen bekommt.
     */
    public function test_a_manual_assignment_survives_the_next_event(): void
    {
        [$user, $organization, $project] = $this->project();
        Team::factory()->for($organization)->create(['name' => 'Kasse']);

        OwnershipRule::factory()->for($project)
            ->matching(OwnershipMatcher::Path, 'src/billing/*', ['#Kasse'])->create();

        $this->ingest($project, $this->crash());

        $issue = Issue::query()->sole();
        $issue->forceFill([
            'assigned_team_id' => null,
            'assigned_user_id' => $user->id,
            'assigned_at' => now(),
        ])->save();

        $this->ingest($project, $this->crash());

        $issue->refresh();

        $this->assertSame($user->id, $issue->assigned_user_id);
        $this->assertNull($issue->assigned_team_id);
    }

    /**
     * Eine Regel, deren Zuständige es nicht (mehr) gibt, weist niemandem etwas
     * zu — und bricht die Aufnahme nicht ab.
     */
    public function test_an_unresolvable_owner_leaves_the_error_unassigned(): void
    {
        [, , $project] = $this->project();

        OwnershipRule::factory()->for($project)
            ->matching(OwnershipMatcher::Path, 'src/billing/*', ['#Gibt-Es-Nicht'])->create();

        $this->ingest($project, $this->crash());

        $issue = Issue::query()->sole();

        $this->assertNull($issue->assigned_team_id);
        $this->assertNull($issue->assigned_user_id);
    }

    /**
     * Der Zuweisungs-Dialog: die Regeln führen die Liste an, sobald der Aufruf
     * sagt, um welchen Fehler es geht. Das ist der Weg, auf dem auch ein
     * Fehler von gestern noch zu seinem Zuständigen kommt.
     */
    public function test_the_assignment_dialog_leads_with_the_rules(): void
    {
        [$user, $organization, $project] = $this->project(autoAssign: false);
        Team::factory()->for($organization)->create(['name' => 'Kasse']);

        OwnershipRule::factory()->for($project)
            ->matching(OwnershipMatcher::Path, 'src/billing/*', ['#Kasse'])->create();

        $this->ingest($project, $this->crash());

        $issue = Issue::query()->sole();

        $this->actingAs($user)
            ->getJson(route('issues.assignment.suggest', ['issue' => $issue->id]))
            ->assertOk()
            ->assertJsonPath('suggestions.0.kind', 'ownership')
            ->assertJsonPath('suggestions.0.value', '#Kasse');
    }

    /**
     * Ohne Kennung — bei einer Sammelaktion — bleibt es bei der reinen
     * Auswahlliste: was für den einen Fehler gilt, gilt nicht für die anderen.
     */
    public function test_the_dialog_suggests_nothing_for_a_bulk_action(): void
    {
        [$user, $organization, $project] = $this->project(autoAssign: false);
        Team::factory()->for($organization)->create(['name' => 'Kasse']);

        OwnershipRule::factory()->for($project)
            ->matching(OwnershipMatcher::Path, 'src/billing/*', ['#Kasse'])->create();

        $this->ingest($project, $this->crash());

        $response = $this->actingAs($user)
            ->getJson(route('issues.assignment.suggest'))
            ->assertOk();

        foreach ($response->json('suggestions') as $suggestion) {
            $this->assertNotSame('ownership', $suggestion['kind']);
        }
    }
}
