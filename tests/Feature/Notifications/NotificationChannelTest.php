<?php

namespace Tests\Feature\Notifications;

use App\Enums\OrganizationRole;
use App\Models\NotificationChannel;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class NotificationChannelTest extends TestCase
{
    use RefreshDatabase;

    public function test_administration_can_set_up_a_channel(): void
    {
        $admin = User::factory()->create();
        $organization = Organization::factory()->withMember($admin, OrganizationRole::Admin)->create();

        $this->actingAs($admin)
            ->post("/organisationen/{$organization->slug}/benachrichtigungen", [
                'type' => 'slack',
                'name' => 'Bereitschaft',
                'config' => ['webhook_url' => 'https://hooks.slack.com/services/T000/B000/xxx'],
            ])
            ->assertSessionHasNoErrors();

        $channel = NotificationChannel::query()->firstOrFail();

        $this->assertSame('slack', $channel->type);
        $this->assertSame('Bereitschaft', $channel->name);
        $this->assertSame('https://hooks.slack.com/services/T000/B000/xxx', $channel->setting('webhook_url'));
        $this->assertTrue($channel->is_active);
    }

    public function test_members_may_not_set_up_a_channel(): void
    {
        $member = User::factory()->create();
        $organization = Organization::factory()->withMember($member, OrganizationRole::Member)->create();

        $this->actingAs($member)
            ->post("/organisationen/{$organization->slug}/benachrichtigungen", [
                'type' => 'slack',
                'name' => 'Heimlich',
                'config' => ['webhook_url' => 'https://hooks.slack.com/services/T000/B000/xxx'],
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('notification_channels', 0);
    }

    public function test_outsiders_do_not_see_the_channels(): void
    {
        $outsider = User::factory()->create();
        $organization = Organization::factory()->withMember(User::factory()->create())->create();

        $this->actingAs($outsider)
            ->get("/organisationen/{$organization->slug}/benachrichtigungen")
            ->assertForbidden();
    }

    public function test_the_page_never_hands_out_credentials(): void
    {
        $admin = User::factory()->create();
        $organization = Organization::factory()->withMember($admin, OrganizationRole::Admin)->create();
        NotificationChannel::factory()->for($organization)->create([
            'name' => 'Eigener Dienst',
            'config' => ['url' => 'https://example.com/errstack', 'secret' => 'streng-geheim-geheim'],
        ]);

        $response = $this->actingAs($admin)
            ->get("/organisationen/{$organization->slug}/benachrichtigungen")
            ->assertOk();

        $response->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('notifications/Index')
                ->where('channels.0.name', 'Eigener Dienst')
                // Die offene Ziel-URL darf zurückkommen, das Geheimnis nie.
                ->where('channels.0.values.url', 'https://example.com/errstack')
                ->missing('channels.0.values.secret')
        );

        $this->assertStringNotContainsString('streng-geheim-geheim', $response->getContent() ?: '');
    }

    public function test_credentials_survive_a_change_that_leaves_them_empty(): void
    {
        $admin = User::factory()->create();
        $organization = Organization::factory()->withMember($admin, OrganizationRole::Admin)->create();
        $channel = NotificationChannel::factory()->for($organization)->create([
            'name' => 'Alt',
            'config' => ['url' => 'https://example.com/errstack', 'secret' => 'streng-geheim-geheim'],
        ]);

        $this->actingAs($admin)
            ->patch("/benachrichtigungen/{$channel->id}", [
                'name' => 'Neu',
                'is_active' => true,
                'config' => ['url' => 'https://example.com/anders', 'secret' => ''],
            ])
            ->assertSessionHasNoErrors();

        $channel->refresh();

        $this->assertSame('Neu', $channel->name);
        $this->assertSame('https://example.com/anders', $channel->setting('url'));
        $this->assertSame('streng-geheim-geheim', $channel->setting('secret'));
    }

    public function test_a_channel_needs_the_fields_of_its_own_kind(): void
    {
        $admin = User::factory()->create();
        $organization = Organization::factory()->withMember($admin, OrganizationRole::Admin)->create();

        // Eine Discord-URL ist für Slack keine gültige Adresse.
        $this->actingAs($admin)
            ->post("/organisationen/{$organization->slug}/benachrichtigungen", [
                'type' => 'slack',
                'name' => 'Falsch',
                'config' => ['webhook_url' => 'https://discord.com/api/webhooks/1/xxx'],
            ])
            ->assertSessionHasErrors('config.webhook_url');

        $this->assertDatabaseCount('notification_channels', 0);
    }

    public function test_unknown_kinds_are_refused(): void
    {
        $admin = User::factory()->create();
        $organization = Organization::factory()->withMember($admin, OrganizationRole::Admin)->create();

        $this->actingAs($admin)
            ->post("/organisationen/{$organization->slug}/benachrichtigungen", [
                'type' => 'brieftaube',
                'name' => 'Luftpost',
                'config' => [],
            ])
            ->assertSessionHasErrors('type');
    }

    public function test_recipients_arrive_line_by_line(): void
    {
        $admin = User::factory()->create();
        $organization = Organization::factory()->withMember($admin, OrganizationRole::Admin)->create();

        $this->actingAs($admin)
            ->post("/organisationen/{$organization->slug}/benachrichtigungen", [
                'type' => 'mail',
                'name' => 'Verteiler',
                'config' => ['recipients' => "team@example.com\nbereitschaft@example.com"],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            ['team@example.com', 'bereitschaft@example.com'],
            NotificationChannel::query()->firstOrFail()->setting('recipients'),
        );
    }

    public function test_a_name_is_used_only_once_per_organization(): void
    {
        $admin = User::factory()->create();
        $organization = Organization::factory()->withMember($admin, OrganizationRole::Admin)->create();
        NotificationChannel::factory()->for($organization)->create(['name' => 'Bereitschaft']);

        $this->actingAs($admin)
            ->post("/organisationen/{$organization->slug}/benachrichtigungen", [
                'type' => 'slack',
                'name' => 'Bereitschaft',
                'config' => ['webhook_url' => 'https://hooks.slack.com/services/T000/B000/xxx'],
            ])
            ->assertSessionHasErrors('name');

        // In einer anderen Organisation darf derselbe Name wieder vorkommen.
        $other = Organization::factory()->withMember($admin, OrganizationRole::Admin)->create();

        $this->actingAs($admin)
            ->post("/organisationen/{$other->slug}/benachrichtigungen", [
                'type' => 'slack',
                'name' => 'Bereitschaft',
                'config' => ['webhook_url' => 'https://hooks.slack.com/services/T000/B000/xxx'],
            ])
            ->assertSessionHasNoErrors();
    }

    public function test_administration_can_delete_a_channel(): void
    {
        $admin = User::factory()->create();
        $organization = Organization::factory()->withMember($admin, OrganizationRole::Admin)->create();
        $channel = NotificationChannel::factory()->for($organization)->create();

        $this->actingAs($admin)
            ->delete("/benachrichtigungen/{$channel->id}")
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('notification_channels', 0);
    }

    public function test_every_member_may_look_at_the_channels(): void
    {
        $viewer = User::factory()->create();
        $organization = Organization::factory()->withMember($viewer, OrganizationRole::Viewer)->create();
        NotificationChannel::factory()->for($organization)->create();

        $this->actingAs($viewer)
            ->get("/organisationen/{$organization->slug}/benachrichtigungen")
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('notifications/Index')
                    ->where('permissions.manage', false)
                    ->has('channels', 1)
            );
    }
}
