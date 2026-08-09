<?php

namespace Tests\Feature\Uptime;

use App\Enums\HttpMethod;
use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\Project;
use App\Models\UptimeMonitor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Die Verwaltung der überwachten Ziele in der Oberfläche.
 *
 * Der Unterschied zwischen Ansehen und Ändern ist hier noch wichtiger als bei
 * den Cronjobs: die Seite beantwortet „ist die Anwendung gerade erreichbar?",
 * und diese Frage stellt sich während einer Störung jeder.
 */
class UptimeMonitorTest extends TestCase
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
        return "/einstellungen/organisationen/{$organization->slug}/projekte/{$project->slug}/erreichbarkeit";
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function attributes(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Startseite',
            'url' => 'https://webshop.test/health',
            'method' => HttpMethod::Get->value,
            'expected_status_codes' => '200-299',
            'interval_seconds' => 60,
            'timeout_seconds' => 10,
            'confirmation_retries' => 1,
            'confirmation_delay_seconds' => 5,
            'failure_threshold' => 1,
            'recovery_threshold' => 1,
        ];
    }

    public function test_a_member_may_view_the_page(): void
    {
        [$user, $organization, $project] = $this->project(OrganizationRole::Member);

        UptimeMonitor::createFor($project, 'Startseite', ['url' => 'https://webshop.test/']);

        $this->actingAs($user)
            ->get($this->path($organization, $project))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('projects/Uptime')
                ->where('permissions.manage', false)
                ->has('monitors', 1)
                ->where('monitors.0.name', 'Startseite')
                ->where('monitors.0.status', 'unknown'));
    }

    public function test_a_member_may_not_create_a_monitor(): void
    {
        [$user, $organization, $project] = $this->project(OrganizationRole::Member);

        $this->actingAs($user)
            ->post($this->path($organization, $project), $this->attributes())
            ->assertForbidden();

        $this->assertSame(0, UptimeMonitor::query()->count());
    }

    /**
     * Der Fall aus der Aufgabenstellung: ein Monitor mit einem Takt von einer
     * Minute lässt sich anlegen — und ist sofort fällig, nicht erst nach einer
     * Minute. Wer eine Überwachung einrichtet, will wissen, ob sie greift.
     */
    public function test_a_monitor_with_a_one_minute_interval_is_created_and_immediately_due(): void
    {
        [$user, $organization, $project] = $this->project();

        $this->actingAs($user)
            ->post($this->path($organization, $project), $this->attributes())
            ->assertRedirect();

        $monitor = UptimeMonitor::query()->sole();

        $this->assertSame('startseite', $monitor->slug);
        $this->assertSame(60, $monitor->interval_seconds);
        $this->assertTrue($monitor->is_active);
        $this->assertNotNull($monitor->next_check_at);
        $this->assertTrue($monitor->next_check_at->lessThanOrEqualTo(now()));
    }

    public function test_the_interval_may_not_fall_below_a_minute(): void
    {
        [$user, $organization, $project] = $this->project();

        $this->actingAs($user)
            ->post($this->path($organization, $project), $this->attributes(['interval_seconds' => 30]))
            ->assertSessionHasErrors('interval_seconds');

        $this->assertSame(0, UptimeMonitor::query()->count());
    }

    /**
     * Eine Adresse ohne Verfahren wäre keine Adresse — und ein anderes Verfahren
     * als http/https wäre ein Werkzeug, um aus dem Netz des Servers heraus
     * anderes anzusprechen.
     */
    public function test_the_url_must_be_http_or_https(): void
    {
        [$user, $organization, $project] = $this->project();

        foreach (['file:///etc/passwd', 'webshop.test', 'ftp://webshop.test'] as $url) {
            $this->actingAs($user)
                ->post($this->path($organization, $project), $this->attributes(['url' => $url]))
                ->assertSessionHasErrors('url');
        }

        $this->assertSame(0, UptimeMonitor::query()->count());
    }

    public function test_the_expected_status_codes_must_be_readable(): void
    {
        [$user, $organization, $project] = $this->project();

        $this->actingAs($user)
            ->post($this->path($organization, $project), $this->attributes(['expected_status_codes' => '2xx']))
            ->assertSessionHasErrors('expected_status_codes');
    }

    /**
     * Zeitgrenze und Bestätigung müssen zusammen in einen Takt passen — sonst
     * gälte der eingestellte Takt stillschweigend nicht mehr.
     */
    public function test_timeout_and_confirmation_must_fit_into_the_interval(): void
    {
        [$user, $organization, $project] = $this->project();

        $this->actingAs($user)
            ->post($this->path($organization, $project), $this->attributes([
                'interval_seconds' => 60,
                'timeout_seconds' => 30,
                'confirmation_retries' => 2,
                'confirmation_delay_seconds' => 10,
            ]))
            ->assertSessionHasErrors('timeout_seconds');
    }

    /**
     * Ein HEAD überträgt keinen Rumpf; eine Inhaltsprüfung daneben würde bei
     * jedem Lauf scheitern und das Ziel dauerhaft als ausgefallen melden.
     */
    public function test_a_content_check_is_rejected_for_head_requests(): void
    {
        [$user, $organization, $project] = $this->project();

        $this->actingAs($user)
            ->post($this->path($organization, $project), $this->attributes([
                'method' => HttpMethod::Head->value,
                'expected_content' => 'Willkommen',
            ]))
            ->assertSessionHasErrors('expected_content');
    }

    /**
     * Leere Kopfzeilen aus dem Formular fallen heraus, gefüllte bleiben in ihrer
     * Reihenfolge stehen.
     */
    public function test_empty_headers_are_dropped(): void
    {
        [$user, $organization, $project] = $this->project();

        $this->actingAs($user)
            ->post($this->path($organization, $project), $this->attributes([
                'headers' => [
                    ['name' => 'Authorization', 'value' => 'Bearer geheim'],
                    ['name' => '', 'value' => ''],
                    ['name' => 'Accept-Language', 'value' => 'de'],
                ],
            ]))
            ->assertRedirect();

        $monitor = UptimeMonitor::query()->sole();

        $this->assertSame([
            ['name' => 'Authorization', 'value' => 'Bearer geheim'],
            ['name' => 'Accept-Language', 'value' => 'de'],
        ], $monitor->headers);
    }

    /**
     * Ändert sich der Takt, muss die Fälligkeit neu gerechnet werden — sonst
     * wartet der Sweep weiter auf den alten Zeitpunkt.
     */
    public function test_changing_the_interval_reschedules_the_next_check(): void
    {
        [$user, $organization, $project] = $this->project();

        $monitor = UptimeMonitor::createFor($project, 'Startseite', [
            'url' => 'https://webshop.test/',
            'interval_seconds' => 3600,
        ]);

        $monitor->next_check_at = now()->addHour();
        $monitor->save();

        $this->actingAs($user)
            ->patch($this->path($organization, $project)."/{$monitor->slug}", $this->attributes([
                'interval_seconds' => 60,
            ]))
            ->assertRedirect();

        $monitor->refresh();

        $this->assertSame(60, $monitor->interval_seconds);
        $this->assertTrue($monitor->next_check_at->lessThanOrEqualTo(now()->addSeconds(61)));
    }

    /**
     * Beim Wiedereinschalten wird sofort geprüft und die Fehlerserie
     * zurückgesetzt: die Zähler stammen aus der Zeit vor der Wartung.
     */
    public function test_re_enabling_resets_the_failure_streak_and_checks_at_once(): void
    {
        [$user, $organization, $project] = $this->project();

        $monitor = UptimeMonitor::createFor($project, 'Startseite', ['url' => 'https://webshop.test/']);
        $monitor->is_active = false;
        $monitor->consecutive_failures = 7;
        $monitor->next_check_at = now()->addDay();
        $monitor->save();

        $this->actingAs($user)
            ->post($this->path($organization, $project)."/{$monitor->slug}/zustand")
            ->assertRedirect();

        $monitor->refresh();

        $this->assertTrue($monitor->is_active);
        $this->assertSame(0, $monitor->consecutive_failures);
        $this->assertTrue($monitor->next_check_at->lessThanOrEqualTo(now()));
    }

    public function test_a_monitor_of_another_project_is_not_reachable(): void
    {
        [$user, $organization, $project] = $this->project();

        $other = Project::factory()->for($organization)->create(['slug' => 'anderes']);
        $monitor = UptimeMonitor::createFor($other, 'Fremd', ['url' => 'https://anderes.test/']);

        $this->actingAs($user)
            ->delete($this->path($organization, $project)."/{$monitor->slug}")
            ->assertNotFound();

        $this->assertSame(1, UptimeMonitor::query()->count());
    }
}
