<?php

namespace Tests\Feature\Organizations;

use App\Enums\OrganizationRole;
use App\Mail\OrganizationInvitationMail;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class InvitationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_administration_can_invite_by_email(): void
    {
        $admin = User::factory()->create();
        $organization = Organization::factory()->withMember($admin, OrganizationRole::Admin)->create();

        $this->actingAs($admin)
            ->from("/einstellungen/organisationen/{$organization->slug}")
            ->post("/einstellungen/organisationen/{$organization->slug}/einladungen", [
                'email' => 'neu@example.com',
                'role' => OrganizationRole::Member->value,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect("/einstellungen/organisationen/{$organization->slug}");

        $this->assertDatabaseHas('organization_invitations', [
            'organization_id' => $organization->id,
            'email' => 'neu@example.com',
            'role' => OrganizationRole::Member->value,
            'invited_by_id' => $admin->id,
        ]);

        Mail::assertQueued(
            OrganizationInvitationMail::class,
            fn (OrganizationInvitationMail $mail): bool => $mail->hasTo('neu@example.com'),
        );
    }

    public function test_members_and_viewers_may_not_invite(): void
    {
        foreach ([OrganizationRole::Member, OrganizationRole::Viewer] as $role) {
            $user = User::factory()->create();
            $organization = Organization::factory()->withMember($user, $role)->create();

            $this->actingAs($user)
                ->post("/einstellungen/organisationen/{$organization->slug}/einladungen", [
                    'email' => 'neu@example.com',
                    'role' => OrganizationRole::Member->value,
                ])
                ->assertForbidden();
        }

        $this->assertDatabaseCount('organization_invitations', 0);
        Mail::assertNothingQueued();
    }

    public function test_administration_may_not_invite_someone_as_owner(): void
    {
        // Sonst führte der Umweg über eine Einladung an die eigene Zweitadresse
        // zur Besitzer-Rolle, die sich direkt nicht vergeben lässt.
        $admin = User::factory()->create();
        $organization = Organization::factory()->withMember($admin, OrganizationRole::Admin)->create();

        $this->actingAs($admin)
            ->post("/einstellungen/organisationen/{$organization->slug}/einladungen", [
                'email' => 'chef@example.com',
                'role' => OrganizationRole::Owner->value,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('organization_invitations', 0);

        $owner = User::factory()->create();
        $organization->setRole($owner, OrganizationRole::Owner);

        $this->actingAs($owner)
            ->post("/einstellungen/organisationen/{$organization->slug}/einladungen", [
                'email' => 'chef@example.com',
                'role' => OrganizationRole::Owner->value,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('organization_invitations', 1);
    }

    public function test_the_role_of_an_open_invitation_can_be_changed(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->withMember($owner)->create();
        $invitation = $organization->invitations()->create([
            'email' => 'wechsel@example.com',
            'role' => OrganizationRole::Member,
        ]);
        $token = $invitation->token;

        $this->actingAs($owner)
            ->patch("/einstellungen/einladungen/{$invitation->id}", ['role' => OrganizationRole::Admin->value])
            ->assertSessionHasNoErrors();

        $invitation->refresh();

        $this->assertSame(OrganizationRole::Admin, $invitation->role);
        // Der Link aus der bereits verschickten Mail bleibt gültig.
        $this->assertSame($token, $invitation->token);
    }

    public function test_administration_may_not_raise_an_invitation_to_owner(): void
    {
        $admin = User::factory()->create();
        $organization = Organization::factory()->withMember($admin, OrganizationRole::Admin)->create();
        $invitation = $organization->invitations()->create([
            'email' => 'bleibt@example.com',
            'role' => OrganizationRole::Member,
        ]);

        $this->actingAs($admin)
            ->patch("/einstellungen/einladungen/{$invitation->id}", ['role' => OrganizationRole::Owner->value])
            ->assertForbidden();

        $this->assertSame(OrganizationRole::Member, $invitation->refresh()->role);
    }

    public function test_an_invitation_never_lowers_the_role_of_an_existing_member(): void
    {
        $admin = User::factory()->create(['email' => 'ada@example.com']);
        $organization = Organization::factory()->create();
        $organization->setRole($admin, OrganizationRole::Admin);

        // Einladung von früher, inzwischen ist die Person längst Verwaltung.
        $invitation = $organization->invitations()->create([
            'email' => 'ada@example.com',
            'role' => OrganizationRole::Viewer,
        ]);

        $this->actingAs($admin)
            ->post("/einladung/{$invitation->token}")
            ->assertRedirect("/einstellungen/organisationen/{$organization->slug}");

        $this->assertSame(OrganizationRole::Admin, $organization->roleFor($admin));
        $this->assertDatabaseMissing('organization_invitations', ['id' => $invitation->id]);
    }

    public function test_the_invite_form_offers_the_owner_role_only_to_owners(): void
    {
        $admin = User::factory()->create();
        $organization = Organization::factory()->withMember($admin, OrganizationRole::Admin)->create();

        $this->actingAs($admin)
            ->get("/einstellungen/organisationen/{$organization->slug}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('invitableRoles', fn (Collection $roles) => $roles->pluck('value')->all() === ['admin', 'member', 'viewer'])
            );

        $owner = User::factory()->create();
        $organization->setRole($owner, OrganizationRole::Owner);

        $this->actingAs($owner)
            ->get("/einstellungen/organisationen/{$organization->slug}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('invitableRoles', fn (Collection $roles) => $roles->pluck('value')->all() === ['owner', 'admin', 'member', 'viewer'])
            );
    }

    public function test_the_same_address_is_not_invited_twice(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->withMember($owner)->create();
        $organization->invitations()->create([
            'email' => 'doppelt@example.com',
            'role' => OrganizationRole::Member,
        ]);

        $this->actingAs($owner)
            ->post("/einstellungen/organisationen/{$organization->slug}/einladungen", [
                'email' => 'doppelt@example.com',
                'role' => OrganizationRole::Member->value,
            ])
            ->assertSessionHasErrors('email');

        $this->assertSame(1, $organization->invitations()->count());
    }

    public function test_existing_members_are_not_invited_again(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create(['email' => 'dabei@example.com']);
        $organization = Organization::factory()->withMember($owner)->create();
        $organization->setRole($member, OrganizationRole::Member);

        $this->actingAs($owner)
            ->post("/einstellungen/organisationen/{$organization->slug}/einladungen", [
                'email' => 'dabei@example.com',
                'role' => OrganizationRole::Member->value,
            ])
            ->assertSessionHasErrors('email');
    }

    public function test_an_invitation_link_leads_to_joining(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->withMember($owner)->create();
        $invitation = $organization->invitations()->create([
            'email' => 'gast@example.com',
            'role' => OrganizationRole::Member,
        ]);
        $invited = User::factory()->create(['email' => 'gast@example.com']);

        $this->actingAs($invited)
            ->get("/einladung/{$invitation->token}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('invitations/Accept')
                ->where('invitation.organization', $organization->name)
                ->where('invitation.isForCurrentUser', true)
            );

        $this->actingAs($invited)
            ->post("/einladung/{$invitation->token}")
            ->assertRedirect("/einstellungen/organisationen/{$organization->slug}");

        $this->assertSame(OrganizationRole::Member, $organization->roleFor($invited));
        $this->assertSame($organization->id, $invited->refresh()->current_organization_id);
        $this->assertDatabaseMissing('organization_invitations', ['id' => $invitation->id]);
    }

    public function test_a_forwarded_link_does_not_work_in_another_account(): void
    {
        $organization = Organization::factory()->create();
        $invitation = $organization->invitations()->create([
            'email' => 'gemeint@example.com',
            'role' => OrganizationRole::Member,
        ]);
        $other = User::factory()->create(['email' => 'jemand.anderes@example.com']);

        $this->actingAs($other)
            ->post("/einladung/{$invitation->token}")
            ->assertForbidden();

        $this->assertFalse($organization->hasMember($other));
    }

    public function test_an_expired_invitation_is_refused(): void
    {
        $organization = Organization::factory()->create();
        $invitation = $organization->invitations()->create([
            'email' => 'spaet@example.com',
            'role' => OrganizationRole::Member,
        ]);
        $invitation->forceFill(['expires_at' => now()->subDay()])->save();
        $invited = User::factory()->create(['email' => 'spaet@example.com']);

        $this->actingAs($invited)
            ->from("/einladung/{$invitation->token}")
            ->post("/einladung/{$invitation->token}")
            ->assertSessionHasErrors('token');

        $this->assertFalse($organization->hasMember($invited));
    }

    public function test_an_unknown_token_leads_nowhere(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/einladung/gibt-es-nicht')
            ->assertNotFound();
    }

    public function test_the_invitation_link_survives_registration(): void
    {
        $organization = Organization::factory()->create();
        $invitation = $organization->invitations()->create([
            'email' => 'neuling@example.com',
            'role' => OrganizationRole::Viewer,
        ]);

        // Ohne Konto führt der Link zur Anmeldung; nach der Registrierung geht es
        // dort weiter, wo die Einladung wartet.
        $this->get("/einladung/{$invitation->token}")->assertRedirect('/login');

        $this->post('/register', [
            'name' => 'Neuling',
            'email' => 'neuling@example.com',
            'password' => 'passwort-1234',
            'password_confirmation' => 'passwort-1234',
        ])->assertRedirect("/einladung/{$invitation->token}");
    }

    public function test_administration_can_withdraw_an_invitation(): void
    {
        $admin = User::factory()->create();
        $organization = Organization::factory()->withMember($admin, OrganizationRole::Admin)->create();
        $invitation = $organization->invitations()->create([
            'email' => 'weg@example.com',
            'role' => OrganizationRole::Member,
        ]);

        $this->actingAs($admin)
            ->from("/einstellungen/organisationen/{$organization->slug}")
            ->delete("/einstellungen/einladungen/{$invitation->id}")
            ->assertRedirect("/einstellungen/organisationen/{$organization->slug}");

        $this->assertDatabaseMissing('organization_invitations', ['id' => $invitation->id]);
    }

    public function test_outsiders_may_not_withdraw_an_invitation(): void
    {
        $organization = Organization::factory()->create();
        $invitation = $organization->invitations()->create([
            'email' => 'bleibt@example.com',
            'role' => OrganizationRole::Member,
        ]);

        $this->actingAs(User::factory()->create())
            ->delete("/einstellungen/einladungen/{$invitation->id}")
            ->assertForbidden();

        $this->assertModelExists($invitation);
    }

    public function test_the_expiry_is_set_when_the_invitation_is_created(): void
    {
        $organization = Organization::factory()->create();
        $invitation = $organization->invitations()->create([
            'email' => 'frist@example.com',
            'role' => OrganizationRole::Member,
        ]);

        $this->assertNotEmpty($invitation->token);
        $this->assertFalse($invitation->isExpired());
        $this->assertTrue(
            $invitation->expires_at->isSameDay(now()->addDays(OrganizationInvitation::LIFETIME_DAYS)),
        );
    }
}
