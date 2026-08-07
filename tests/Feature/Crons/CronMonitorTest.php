<?php

namespace Tests\Feature\Crons;

use App\Enums\CronIntervalUnit;
use App\Enums\CronScheduleType;
use App\Enums\OrganizationRole;
use App\Models\CronMonitor;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Die Verwaltung der überwachten Cronjobs in der Oberfläche.
 *
 * Der Unterschied zwischen Ansehen und Ändern ist hier wichtiger als anderswo:
 * die Seite beantwortet „ist der nächtliche Import durchgelaufen?", und diese
 * Frage stellt sich nicht nur die Verwaltung.
 */
class CronMonitorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{User, Organization, Project}
     */
    private function project(OrganizationRole $role = OrganizationRole::Owner): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user, $role)->create();
        $project = Project::factory()->for($organization)->create(['name' => 'Webshop', 'slug' => 'webshop']);

        return [$user, $organization, $project];
    }

    private function path(Organization $organization, Project $project): string
    {
        return "/organisationen/{$organization->slug}/projekte/{$project->slug}/cronjobs";
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Nächtlicher Import',
            'schedule_type' => CronScheduleType::Crontab->value,
            'schedule_expression' => '0 2 * * *',
            'timezone' => 'Europe/Berlin',
            'checkin_margin_minutes' => 15,
            'max_runtime_minutes' => 60,
            'failure_tolerance' => 1,
            'recovery_tolerance' => 1,
        ];
    }

    /**
     * Ein angelegter Monitor mit Zeitplan. Ohne Zeitplan gäbe es keinen Termin —
     * die Eingabeprüfung lässt das gar nicht erst zu, und ein Testaufbau soll
     * hier nicht großzügiger sein als das Formular.
     */
    private function monitor(Project $project, string $name = 'Import'): CronMonitor
    {
        return CronMonitor::createFor($project, $name, ['schedule_expression' => '0 2 * * *']);
    }

    public function test_a_monitor_is_created_with_a_slug_and_a_first_slot(): void
    {
        [$user, $organization, $project] = $this->project();

        $response = $this->actingAs($user)
            ->post($this->path($organization, $project), $this->attributes());

        $response->assertRedirect();

        $monitor = CronMonitor::query()->sole();
        $this->assertSame('nachtlicher-import', $monitor->slug);
        $this->assertSame('Europe/Berlin', $monitor->timezone);
        $this->assertSame(15, $monitor->checkin_margin_minutes);

        // Ohne Termin wäre der Monitor für die Prüfung unsichtbar und würde nie
        // einen Ausfall melden.
        $this->assertNotNull($monitor->next_due_at);
    }

    public function test_the_slug_is_unique_within_the_project(): void
    {
        [$user, $organization, $project] = $this->project();

        $this->actingAs($user)->post($this->path($organization, $project), $this->attributes());
        $this->actingAs($user)->post($this->path($organization, $project), $this->attributes());

        $this->assertSame(
            ['nachtlicher-import', 'nachtlicher-import-2'],
            CronMonitor::query()->orderBy('id')->pluck('slug')->all(),
        );
    }

    public function test_an_unreadable_cron_expression_is_refused(): void
    {
        [$user, $organization, $project] = $this->project();

        $response = $this->actingAs($user)->post(
            $this->path($organization, $project),
            $this->attributes(['schedule_expression' => 'jeden zweiten Dienstag']),
        );

        $response->assertSessionHasErrors('schedule_expression');
        $this->assertSame(0, CronMonitor::query()->count());
    }

    /**
     * Ein Zeitplan ist Pflicht — in der einen oder der anderen Form. Ein
     * Monitor ohne Termin könnte nie feststellen, dass etwas ausgeblieben ist.
     */
    public function test_an_interval_schedule_needs_value_and_unit(): void
    {
        [$user, $organization, $project] = $this->project();

        $response = $this->actingAs($user)->post($this->path($organization, $project), $this->attributes([
            'schedule_type' => CronScheduleType::Interval->value,
            'schedule_expression' => null,
        ]));

        $response->assertSessionHasErrors(['interval_value', 'interval_unit']);
    }

    /**
     * Beim Wechsel der Zeitplan-Form werden die Felder der anderen geleert —
     * sonst stünde beim Zurückwechseln plötzlich wieder ein Zeitplan da, den
     * niemand mehr erwartet.
     */
    public function test_switching_the_schedule_form_clears_the_other_fields(): void
    {
        [$user, $organization, $project] = $this->project();
        $monitor = $this->monitor($project);

        $this->actingAs($user)->patch(
            $this->path($organization, $project)."/{$monitor->slug}",
            $this->attributes([
                'name' => 'Import',
                'schedule_type' => CronScheduleType::Interval->value,
                'interval_value' => 15,
                'interval_unit' => CronIntervalUnit::Minute->value,
            ]),
        );

        $monitor->refresh();
        $this->assertSame(CronScheduleType::Interval, $monitor->schedule_type);
        $this->assertNull($monitor->schedule_expression);
        $this->assertSame(15, $monitor->interval_value);
    }

    /**
     * Ändert sich der Zeitplan, muss der Termin neu gerechnet werden — sonst
     * wartet die Prüfung weiter auf den alten und meldet einen Ausfall, den es
     * nicht gibt.
     */
    public function test_changing_the_schedule_recalculates_the_next_slot(): void
    {
        [$user, $organization, $project] = $this->project();
        $monitor = $this->monitor($project);

        $before = $monitor->next_due_at;

        $this->actingAs($user)->patch(
            $this->path($organization, $project)."/{$monitor->slug}",
            $this->attributes(['name' => 'Import', 'schedule_expression' => '*/5 * * * *']),
        );

        $this->assertTrue($before->greaterThan($monitor->refresh()->next_due_at));
    }

    /**
     * Die Kennung bleibt, auch wenn der Name sich ändert: sie steht im Code des
     * Jobs, und eine Umbenennung würde die Check-ins stillschweigend abreißen
     * lassen.
     */
    public function test_renaming_leaves_the_slug_untouched(): void
    {
        [$user, $organization, $project] = $this->project();
        $monitor = $this->monitor($project);

        $this->actingAs($user)->patch(
            $this->path($organization, $project)."/{$monitor->slug}",
            $this->attributes(['name' => 'Ganz anderer Name']),
        );

        $monitor->refresh();
        $this->assertSame('Ganz anderer Name', $monitor->name);
        $this->assertSame('import', $monitor->slug);
    }

    /**
     * Beim Wiedereinschalten wird der Termin neu gesetzt — der Zeitplan hat in
     * der Zwischenzeit weitergezählt, und ohne das käme sofort eine ganze Reihe
     * verpasster Läufe.
     */
    public function test_re_enabling_a_monitor_sets_a_fresh_slot(): void
    {
        [$user, $organization, $project] = $this->project();
        $monitor = $this->monitor($project);

        $monitor->update(['is_active' => false, 'next_due_at' => now()->subWeek(), 'consecutive_failures' => 5]);

        $this->actingAs($user)->post($this->path($organization, $project)."/{$monitor->slug}/zustand");

        $monitor->refresh();
        $this->assertTrue($monitor->is_active);
        $this->assertTrue($monitor->next_due_at->greaterThan(now()));
        $this->assertSame(0, $monitor->consecutive_failures);
    }

    public function test_a_monitor_can_be_deleted(): void
    {
        [$user, $organization, $project] = $this->project();
        $monitor = $this->monitor($project);

        $this->actingAs($user)
            ->delete($this->path($organization, $project)."/{$monitor->slug}")
            ->assertRedirect();

        $this->assertSame(0, CronMonitor::query()->count());
    }

    /**
     * Ein Monitor ist nur über sein eigenes Projekt erreichbar — sonst ließe
     * sich mit einer fremden Kennung in der Adresse an fremden Jobs drehen.
     */
    public function test_a_monitor_of_another_project_is_not_reachable(): void
    {
        [$user, $organization, $project] = $this->project();
        $other = Project::factory()->for($organization)->create(['slug' => 'anderes']);
        $monitor = $this->monitor($other);

        $this->actingAs($user)
            ->delete($this->path($organization, $project)."/{$monitor->slug}")
            ->assertNotFound();

        $this->assertSame(1, CronMonitor::query()->count());
    }

    /**
     * Lesend darf jedes Mitglied hinein — der Zustand der Jobs ist der Grund,
     * warum jemand nachschaut.
     */
    public function test_a_plain_member_sees_the_page_without_the_forms(): void
    {
        [$user, $organization, $project] = $this->project(OrganizationRole::Member);
        $this->monitor($project);

        $response = $this->actingAs($user)->get($this->path($organization, $project));

        $response->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('projects/Crons')
                ->where('permissions.manage', false)
                ->has('monitors', 1)
        );
    }

    public function test_a_plain_member_may_not_change_anything(): void
    {
        [$user, $organization, $project] = $this->project(OrganizationRole::Member);

        $this->actingAs($user)
            ->post($this->path($organization, $project), $this->attributes())
            ->assertForbidden();

        $this->assertSame(0, CronMonitor::query()->count());
    }

    /**
     * Die Check-in-Adresse enthält den öffentlichen Schlüssel und steht deshalb
     * unter derselben Bedingung wie die DSN.
     */
    public function test_the_check_in_url_is_hidden_from_those_who_may_not_see_keys(): void
    {
        [$owner, $organization, $project] = $this->project();
        $this->monitor($project);

        $this->actingAs($owner)
            ->get($this->path($organization, $project))
            ->assertInertia(fn (AssertableInertia $page) => $page->where(
                'monitors.0.checkInUrl',
                fn (?string $url): bool => is_string($url) && str_contains($url, '/cron/import/'),
            ));

        $member = User::factory()->create();
        $organization->setRole($member, OrganizationRole::Member);

        $this->actingAs($member)
            ->get($this->path($organization, $project))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('monitors.0.checkInUrl', null));
    }
}
