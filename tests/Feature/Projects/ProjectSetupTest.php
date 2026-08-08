<?php

namespace Tests\Feature\Projects;

use App\Enums\DiscardReason;
use App\Enums\OrganizationRole;
use App\Enums\Platform;
use App\Models\IngestDiscard;
use App\Models\IngestPayload;
use App\Models\Issue;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Support\Setup\SetupGuide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Der Einrichtungs-Assistent (O8).
 */
class ProjectSetupTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{User, Organization, Project}
     */
    private function project(OrganizationRole $role = OrganizationRole::Owner, Platform $platform = Platform::Php): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user, $role)->create();
        $project = Project::createFor($organization, 'Webshop', $platform);

        return [$user, $organization, $project];
    }

    private function path(Organization $organization, Project $project): string
    {
        return "/organisationen/{$organization->slug}/projekte/{$project->slug}/einrichtung";
    }

    public function test_a_new_project_leads_straight_into_the_wizard(): void
    {
        [$user, $organization] = $this->project();

        $response = $this->actingAs($user)->post("/organisationen/{$organization->slug}/projekte", [
            'name' => 'Kasse',
            'platform' => Platform::Node->value,
        ]);

        $project = Project::query()->where('slug', 'kasse')->sole();

        $response->assertRedirect($this->path($organization, $project));
    }

    public function test_the_wizard_shows_the_dsn_of_the_project(): void
    {
        [$user, $organization, $project] = $this->project();
        $key = $project->keys()->sole();

        $this->actingAs($user)
            ->get($this->path($organization, $project))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('projects/Setup')
                ->where('dsn', $key->dsn())
                ->where('keyName', $key->name)
                ->where('state.received', false)
            );
    }

    public function test_the_example_carries_the_dsn_for_every_offered_guide(): void
    {
        [$user, $organization, $project] = $this->project();
        $dsn = $project->keys()->sole()->dsn();

        // Der Nachweis für „für mindestens fünf Plattformen ein fertiges
        // Beispiel mit eingesetzter DSN" — und zwar über jede angebotene
        // Anleitung, nicht über eine Stichprobe.
        $this->assertGreaterThanOrEqual(5, count(SetupGuide::cases()));

        foreach (SetupGuide::cases() as $guide) {
            $this->actingAs($user)
                ->get($this->path($organization, $project).'?anleitung='.$guide->value)
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->component('projects/Setup')
                    ->where('guide.value', $guide->value)
                    ->where('guide.package', $guide->package())
                    ->where('guide.steps.configure', fn (string $code): bool => str_contains($code, $dsn))
                );
        }
    }

    public function test_the_wizard_opens_with_the_platform_of_the_project(): void
    {
        [$user, $organization, $project] = $this->project(platform: Platform::Python);

        $this->actingAs($user)
            ->get($this->path($organization, $project))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('guide.value', SetupGuide::Python->value));
    }

    /**
     * Eine unbekannte Anleitung in der Adresszeile ist eine alte Verknüpfung und
     * kein Fehler: der Assistent fällt auf die Vorauswahl zurück, statt eine
     * Fehlerseite zu zeigen.
     */
    public function test_an_unknown_guide_falls_back_to_the_default(): void
    {
        [$user, $organization, $project] = $this->project(platform: Platform::Node);

        $this->actingAs($user)
            ->get($this->path($organization, $project).'?anleitung=cobol')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('guide.value', SetupGuide::Node->value));
    }

    /**
     * Ein Projekt ohne passende Anleitung („Sonstige") beginnt trotzdem mit
     * einer — ein leerer Assistent wäre die Sackgasse.
     */
    public function test_a_project_without_a_matching_guide_still_gets_one(): void
    {
        [$user, $organization, $project] = $this->project(platform: Platform::Other);

        $this->actingAs($user)
            ->get($this->path($organization, $project))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('guide.value', SetupGuide::cases()[0]->value));
    }

    public function test_the_wait_screen_reports_the_first_accepted_event(): void
    {
        [$user, $organization, $project] = $this->project();

        $this->actingAs($user)
            ->getJson($this->path($organization, $project).'/stand')
            ->assertOk()
            ->assertJson(['received' => false, 'issue' => null]);

        IngestPayload::factory()->for($project)->create(['sdk' => 'sentry.php/4.30.0']);

        $this->actingAs($user)
            ->getJson($this->path($organization, $project).'/stand')
            ->assertOk()
            ->assertJson(['received' => true, 'sdk' => 'sentry.php/4.30.0', 'issue' => null]);
    }

    /**
     * Der Verweis auf den Fehler kommt nach, sobald die Verarbeitung ihn
     * angelegt hat — der Erfolg wird schon vorher gemeldet.
     */
    public function test_the_wait_screen_links_the_error_once_it_exists(): void
    {
        [$user, $organization, $project] = $this->project();

        IngestPayload::factory()->for($project)->create();
        $issue = Issue::factory()->for($project)->create(['title' => 'RuntimeException: Probe']);

        $this->actingAs($user)
            ->getJson($this->path($organization, $project).'/stand')
            ->assertOk()
            ->assertJson([
                'received' => true,
                'issue' => [
                    'title' => 'RuntimeException: Probe',
                    'href' => route('issues.show', $issue),
                ],
            ]);
    }

    public function test_the_help_reports_what_was_discarded(): void
    {
        [$user, $organization, $project] = $this->project();

        IngestDiscard::factory()->for($project)->create([
            'reason' => DiscardReason::Filtered->value,
            'quantity' => 3,
        ]);

        $this->actingAs($user)
            ->getJson($this->path($organization, $project).'/stand')
            ->assertOk()
            ->assertJson(['discards' => [[
                'reason' => DiscardReason::Filtered->value,
                'label' => DiscardReason::Filtered->label(),
                'origin' => 'server',
                'quantity' => 3,
            ]]]);
    }

    /**
     * Was ein SDK selbst verworfen hat, trägt dessen eigene Bezeichnung — wir
     * kennen die Liste nicht und erfinden keine Übersetzung dafür.
     */
    public function test_a_discard_reported_by_the_sdk_keeps_its_own_name(): void
    {
        [$user, $organization, $project] = $this->project();

        IngestDiscard::factory()->for($project)->fromClient('queue_overflow')->create(['quantity' => 7]);

        $this->actingAs($user)
            ->getJson($this->path($organization, $project).'/stand')
            ->assertOk()
            ->assertJson(['discards' => [[
                'reason' => 'queue_overflow',
                'label' => 'queue_overflow',
                'origin' => 'client',
                'quantity' => 7,
            ]]]);
    }

    /**
     * Eine Verwerfung von letzter Woche erklärt nicht, warum die Probe von eben
     * nicht ankommt.
     */
    public function test_old_discards_stay_out_of_the_help(): void
    {
        [$user, $organization, $project] = $this->project();

        IngestDiscard::factory()->for($project)->create([
            'bucket' => now()->subDays(3),
            'quantity' => 99,
        ]);

        $this->actingAs($user)
            ->getJson($this->path($organization, $project).'/stand')
            ->assertOk()
            ->assertJsonCount(0, 'discards');
    }

    /**
     * Ohne aktiven Schlüssel gibt es nichts zu kopieren — und der Assistent sagt
     * das, statt ein Beispiel mit leerer DSN anzubieten.
     */
    public function test_without_an_active_key_there_is_no_dsn(): void
    {
        [$user, $organization, $project] = $this->project();
        $project->keys()->sole()->update(['active' => false]);

        $this->actingAs($user)
            ->get($this->path($organization, $project))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('dsn', null));
    }

    /**
     * Die Seite zeigt die DSN im Klartext und braucht deshalb dasselbe Recht wie
     * die Schlüssel-Seite: ein Mitglied darf das Projekt ansehen, den Zugang zur
     * Datenaufnahme aber nicht ablesen.
     */
    public function test_a_member_without_the_key_permission_is_turned_away(): void
    {
        [$user, $organization, $project] = $this->project(OrganizationRole::Member);

        $this->actingAs($user)->get($this->path($organization, $project))->assertForbidden();
        $this->actingAs($user)->getJson($this->path($organization, $project).'/stand')->assertForbidden();
    }

    public function test_someone_from_outside_the_organization_is_turned_away(): void
    {
        [, $organization, $project] = $this->project();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get($this->path($organization, $project))->assertForbidden();
    }

    public function test_the_project_settings_link_to_the_wizard(): void
    {
        [$user, $organization, $project] = $this->project();

        $this->actingAs($user)
            ->get("/organisationen/{$organization->slug}/projekte/{$project->slug}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                // Die Nutzlast trägt die volle Adresse (`route()`), nicht den
                // Pfad — der Vergleich muss dieselbe Quelle nehmen.
                ->where('project.setupHref', route('projects.setup.index', [$organization, $project]))
            );
    }

    /**
     * Wer die Schlüssel nicht verwalten darf, bekommt den Weg dorthin auch nicht
     * angeboten — sonst führte der Link in eine Absage.
     */
    public function test_a_member_is_not_offered_the_wizard(): void
    {
        [$user, $organization, $project] = $this->project(OrganizationRole::Member);

        $this->actingAs($user)
            ->get("/organisationen/{$organization->slug}/projekte/{$project->slug}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('project.setupHref', null));
    }
}
