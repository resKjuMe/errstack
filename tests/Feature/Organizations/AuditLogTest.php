<?php

namespace Tests\Feature\Organizations;

use App\Enums\AuditAction;
use App\Enums\OrganizationRole;
use App\Models\AuditLogEntry;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;
use LogicException;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_role_change_is_recorded_with_actor_time_address_and_values(): void
    {
        $this->freezeSecond();

        $admin = User::factory()->create();
        $member = User::factory()->create();
        $organization = Organization::factory()->withMember($admin, OrganizationRole::Admin)->create();
        $membership = $organization->setRole($member, OrganizationRole::Viewer);

        $this->actingAs($admin)
            ->patch("/mitgliedschaften/{$membership->id}", ['role' => OrganizationRole::Member->value])
            ->assertSessionHasNoErrors();

        $entry = $this->lastEntry($organization);

        $this->assertSame(AuditAction::MembershipRoleChanged, $entry->action);
        $this->assertSame($admin->id, $entry->actor_id);
        $this->assertSame($admin->name, $entry->actor_name);
        $this->assertSame($admin->email, $entry->actor_email);
        $this->assertSame($member->name, $entry->subject_label);
        $this->assertSame('127.0.0.1', $entry->ip_address);
        $this->assertEquals(now(), $entry->created_at);
        $this->assertSame(
            ['Rolle' => ['before' => 'Lesend', 'after' => 'Mitglied']],
            $entry->changed_values,
        );
    }

    public function test_a_role_change_without_effect_is_not_recorded(): void
    {
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $organization = Organization::factory()->withMember($admin, OrganizationRole::Admin)->create();
        $membership = $organization->setRole($member, OrganizationRole::Member);

        $this->actingAs($admin)
            ->patch("/mitgliedschaften/{$membership->id}", ['role' => OrganizationRole::Member->value])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $organization->auditLogEntries()->count());
    }

    public function test_removing_a_member_and_leaving_are_told_apart(): void
    {
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $leaver = User::factory()->create();
        $organization = Organization::factory()->withMember($admin, OrganizationRole::Admin)->create();
        $membership = $organization->setRole($member, OrganizationRole::Member);
        $ownMembership = $organization->setRole($leaver, OrganizationRole::Viewer);

        $this->actingAs($admin)->delete("/mitgliedschaften/{$membership->id}");
        $removed = $this->lastEntry($organization);

        $this->actingAs($leaver)->delete("/mitgliedschaften/{$ownMembership->id}");
        $left = $this->lastEntry($organization);

        $this->assertSame(AuditAction::MembershipRemoved, $removed->action);
        $this->assertSame($member->name, $removed->subject_label);
        $this->assertSame(['Rolle' => ['before' => 'Mitglied', 'after' => null]], $removed->changed_values);

        $this->assertSame(AuditAction::MembershipLeft, $left->action);
        $this->assertSame($leaver->id, $left->actor_id);
        $this->assertSame($leaver->name, $left->subject_label);
    }

    public function test_the_whole_invitation_lifecycle_is_recorded(): void
    {
        $admin = User::factory()->create();
        $organization = Organization::factory()->withMember($admin, OrganizationRole::Admin)->create();

        $this->actingAs($admin)
            ->post("/organisationen/{$organization->slug}/einladungen", [
                'email' => 'neu@example.com',
                'role' => OrganizationRole::Viewer->value,
            ])
            ->assertSessionHasNoErrors();

        $invitation = $organization->invitations()->firstOrFail();
        $sent = $this->lastEntry($organization);

        $this->actingAs($admin)->patch("/einladungen/{$invitation->id}", [
            'role' => OrganizationRole::Member->value,
        ]);
        $changed = $this->lastEntry($organization);

        $this->actingAs($admin)->delete("/einladungen/{$invitation->id}");
        $revoked = $this->lastEntry($organization);

        $this->assertSame(AuditAction::InvitationSent, $sent->action);
        $this->assertSame('neu@example.com', $sent->subject_label);
        $this->assertSame(['Rolle' => ['before' => null, 'after' => 'Lesend']], $sent->changed_values);

        $this->assertSame(AuditAction::InvitationRoleChanged, $changed->action);
        $this->assertSame(['Rolle' => ['before' => 'Lesend', 'after' => 'Mitglied']], $changed->changed_values);

        $this->assertSame(AuditAction::InvitationRevoked, $revoked->action);
        $this->assertSame(['Rolle' => ['before' => 'Mitglied', 'after' => null]], $revoked->changed_values);
    }

    public function test_accepting_an_invitation_is_recorded(): void
    {
        $organization = Organization::factory()->create();
        $invited = User::factory()->create(['email' => 'neu@example.com']);

        $invitation = $organization->invitations()->create([
            'email' => 'neu@example.com',
            'role' => OrganizationRole::Member,
        ]);

        $this->actingAs($invited)->post("/einladung/{$invitation->token}");

        $entry = $this->lastEntry($organization);

        $this->assertSame(AuditAction::InvitationAccepted, $entry->action);
        $this->assertSame($invited->id, $entry->actor_id);
        $this->assertSame($invited->name, $entry->subject_label);
        $this->assertSame(['Rolle' => ['before' => null, 'after' => 'Mitglied']], $entry->changed_values);
    }

    public function test_organization_and_team_actions_are_recorded(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->post('/organisationen', ['name' => 'Nordlicht']);
        $organization = Organization::query()->where('name', 'Nordlicht')->firstOrFail();

        $this->actingAs($owner)->patch("/organisationen/{$organization->slug}", ['name' => 'Südlicht']);
        $this->actingAs($owner)->post("/organisationen/{$organization->slug}/teams", ['name' => 'Bereitschaft']);

        $team = $organization->teams()->firstOrFail();

        $this->actingAs($owner)->patch("/teams/{$team->id}", ['name' => 'Rufbereitschaft']);
        $this->actingAs($owner)->post("/teams/{$team->id}/mitglieder", ['user_id' => $owner->id]);
        $this->actingAs($owner)->delete("/teams/{$team->id}/mitglieder/{$owner->id}");
        $this->actingAs($owner)->delete("/teams/{$team->id}");

        $this->assertSame([
            AuditAction::OrganizationCreated,
            AuditAction::OrganizationUpdated,
            AuditAction::TeamCreated,
            AuditAction::TeamUpdated,
            AuditAction::TeamMemberAdded,
            AuditAction::TeamMemberRemoved,
            AuditAction::TeamDeleted,
        ], $organization->auditLogEntries()->orderBy('id')->get()->pluck('action')->all());
    }

    public function test_an_entry_survives_the_deletion_of_its_subject(): void
    {
        $admin = User::factory()->create();
        $organization = Organization::factory()->withMember($admin, OrganizationRole::Admin)->create();
        $team = Team::factory()->for($organization)->create(['name' => 'Bereitschaft']);

        $this->actingAs($admin)->delete("/teams/{$team->id}");

        $entry = $this->lastEntry($organization);

        $this->assertDatabaseMissing('teams', ['id' => $team->id]);
        $this->assertSame('Bereitschaft', $entry->subject_label);
    }

    public function test_an_entry_cannot_be_changed(): void
    {
        $entry = AuditLogEntry::factory()->create();

        $this->expectException(LogicException::class);

        $entry->update(['actor_name' => 'Jemand anderes']);
    }

    public function test_an_entry_cannot_be_deleted(): void
    {
        $entry = AuditLogEntry::factory()->create();

        $this->expectException(LogicException::class);

        $entry->delete();
    }

    public function test_only_the_retention_removes_entries(): void
    {
        $organization = Organization::factory()->create();

        $this->travelTo(Carbon::parse('2026-01-01 09:00'));
        $old = AuditLogEntry::factory()->for($organization)->create();

        $this->travelTo(Carbon::parse('2026-06-01 09:00'));
        $recent = AuditLogEntry::factory()->for($organization)->create();

        $this->travelBack();

        $removed = AuditLogEntry::pruneOlderThan(Carbon::parse('2026-03-01 00:00'));

        $this->assertSame(1, $removed);
        $this->assertDatabaseMissing('audit_log_entries', ['id' => $old->id]);
        $this->assertDatabaseHas('audit_log_entries', ['id' => $recent->id]);
    }

    public function test_the_log_can_be_filtered_by_actor_action_and_period(): void
    {
        $admin = User::factory()->create();
        $other = User::factory()->create();
        $organization = Organization::factory()->withMember($admin, OrganizationRole::Admin)->create();

        $this->travelTo(Carbon::parse('2026-03-10 10:00'));
        AuditLogEntry::factory()->for($organization)->by($admin)->create([
            'action' => AuditAction::TeamCreated,
        ]);

        $this->travelTo(Carbon::parse('2026-03-20 10:00'));
        AuditLogEntry::factory()->for($organization)->by($other)->create([
            'action' => AuditAction::MembershipRemoved,
        ]);

        $this->travelBack();

        $url = "/organisationen/{$organization->slug}/protokoll";

        $this->actingAs($admin)
            ->get("{$url}?actor={$admin->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('entries.data', fn (Collection $entries): bool => $entries->count() === 1
                    && $entries->first()['action'] === AuditAction::TeamCreated->value));

        $this->actingAs($admin)
            ->get("{$url}?action=".AuditAction::MembershipRemoved->value)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('entries.data', fn (Collection $entries): bool => $entries->count() === 1
                    && $entries->first()['actorName'] === $other->name));

        // Der Zeitraum gilt einschließlich seiner Ränder.
        $this->actingAs($admin)
            ->get("{$url}?from=2026-03-20&to=2026-03-20")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('entries.data', fn (Collection $entries): bool => $entries->count() === 1
                    && $entries->first()['action'] === AuditAction::MembershipRemoved->value));
    }

    public function test_a_period_that_ends_before_it_starts_is_refused(): void
    {
        $admin = User::factory()->create();
        $organization = Organization::factory()->withMember($admin, OrganizationRole::Admin)->create();

        $this->actingAs($admin)
            ->get("/organisationen/{$organization->slug}/protokoll?from=2026-03-20&to=2026-03-10")
            ->assertSessionHasErrors('to');
    }

    public function test_the_log_is_exported_as_csv_along_the_same_filters(): void
    {
        $admin = User::factory()->create();
        $organization = Organization::factory()->withMember($admin, OrganizationRole::Admin)->create();

        AuditLogEntry::factory()->for($organization)->by($admin)->create([
            'action' => AuditAction::TeamCreated,
            'subject_label' => 'Bereitschaft',
            'changed_values' => ['Name' => ['before' => null, 'after' => 'Bereitschaft']],
        ]);
        AuditLogEntry::factory()->for($organization)->by($admin)->create([
            'action' => AuditAction::MembershipRemoved,
            'subject_label' => 'Erika Musterfrau',
        ]);

        $response = $this->actingAs($admin)
            ->get("/organisationen/{$organization->slug}/protokoll/export?action=".AuditAction::TeamCreated->value);

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $response->assertDownload("protokoll-{$organization->slug}-".now()->format('Y-m-d').'.csv');

        $csv = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Zeitpunkt;Nutzer;E-Mail;Aktion;Betroffen;Änderungen;IP-Adresse', $csv);
        $this->assertStringContainsString('Team angelegt', $csv);
        $this->assertStringContainsString('Name: — → Bereitschaft', $csv);
        $this->assertStringNotContainsString('Erika Musterfrau', $csv);
    }

    public function test_only_the_administration_sees_the_log(): void
    {
        $organization = Organization::factory()->create();
        $url = "/organisationen/{$organization->slug}/protokoll";

        foreach ([OrganizationRole::Member, OrganizationRole::Viewer] as $role) {
            $user = User::factory()->create();
            $organization->setRole($user, $role);

            $this->actingAs($user)->get($url)->assertForbidden();
            $this->actingAs($user)->get("{$url}/export")->assertForbidden();
        }

        foreach ([OrganizationRole::Admin, OrganizationRole::Owner] as $role) {
            $user = User::factory()->create();
            $organization->setRole($user, $role);

            $this->actingAs($user)->get($url)->assertOk();
        }

        $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
    }

    public function test_the_organization_page_offers_the_log_only_to_the_administration(): void
    {
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $organization = Organization::factory()->withMember($admin, OrganizationRole::Admin)->create();
        $organization->setRole($member, OrganizationRole::Member);

        $this->actingAs($admin)
            ->get("/organisationen/{$organization->slug}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('permissions.viewAuditLog', true)
                ->where('auditLogHref', route('organizations.audit-log.index', $organization)));

        $this->actingAs($member)
            ->get("/organisationen/{$organization->slug}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('permissions.viewAuditLog', false));
    }

    public function test_the_log_of_another_organization_stays_out(): void
    {
        $admin = User::factory()->create();
        $organization = Organization::factory()->withMember($admin, OrganizationRole::Admin)->create();
        $foreign = Organization::factory()->create();

        AuditLogEntry::factory()->for($organization)->by($admin)->create();
        AuditLogEntry::factory()->for($foreign)->create();

        $this->actingAs($admin)
            ->get("/organisationen/{$organization->slug}/protokoll")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('entries.data', fn (Collection $entries): bool => $entries->count() === 1));
    }

    private function lastEntry(Organization $organization): AuditLogEntry
    {
        $entry = $organization->auditLogEntries()->orderByDesc('id')->first();

        $this->assertNotNull($entry, 'Es wurde kein Protokolleintrag geschrieben.');

        return $entry;
    }
}
