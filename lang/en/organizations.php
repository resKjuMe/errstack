<?php

// Organizations (resources/js/shell/pages/organizations) together with the
// messages from App\Http\Controllers\OrganizationController,
// MembershipController and OrganizationInvitationController.
return [

    'index' => [
        'title' => 'Organizations',
        'help' => 'An organization is the bracket around everything else: projects, error reports and alerts always belong to exactly one. Whoever is invited sees only its data.',
        'empty_title' => 'No organization yet',
        'empty_description' => 'Create one to get started — or wait for an invitation by email.',
        'own_role' => 'Your role: :role',
        'current' => 'Active',
        'switch' => 'Make active',
    ],

    'create' => [
        'title' => 'New organization',
        'description' => 'Whoever creates it becomes its owner.',
        'name' => 'Name',
        'submit' => 'Create',
    ],

    'show' => [
        'help' => 'Roles decide who may do what: owner everything, administration the organization including members and teams, member the daily work, read-only just looking.',
        'own_role' => 'Your role:',
        'audit_log' => 'Audit log',
        'privacy' => 'Privacy',
        'repositories' => 'Repositories',
        'all_organizations' => 'All organizations',
    ],

    'settings' => [
        'title' => 'Details',
        'description' => 'The name of the organization.',
        'name' => 'Name',
        'submit' => 'Save',
    ],

    'members' => [
        'title' => 'Members',
        'description_manage' => 'Change a role or remove a member.',
        'description_read' => 'Who belongs to this organization.',
        'self' => '(that is you)',
        'hint_self' => 'You do not change your own role.',
        'hint_owner' => 'Only an owner changes an owner.',
        'role_of' => 'Role of :name',
        'leave' => 'Leave',
        'remove' => 'Remove',
    ],

    'invitations' => [
        'title' => 'Invitations',
        'description' => 'The link in the email leads straight to joining — an account can still be created along the way.',
        'email' => 'Email address',
        'role' => 'Role',
        'submit' => 'Invite',
        'expired' => 'expired',
        'valid_until' => 'valid until :date',
        'role_of' => 'Role of the invitation to :email',
        'withdraw' => 'Withdraw',
    ],

    'notifications' => [
        'title' => 'Notifications',
        'description' => 'Where Errstack reports to: email, Slack, Discord, Teams or a webhook of your own.',
        'link' => 'Channels and delivery log',
    ],

    'teams' => [
        'title' => 'Teams',
        'description' => 'Teams group members inside the organization.',
        'empty' => 'No teams yet.',
        'new' => 'New team',
        'submit' => 'Create',
    ],

    'delete' => [
        'title' => 'Delete organization',
        'description' => 'Deleting the organization removes memberships, teams and all attached data — irreversibly.',
        'submit' => 'Delete organization',
    ],

    'errors' => [
        'not_a_member' => 'This account does not belong to that organization.',
    ],

    'flash' => [
        'created' => 'Organization ":name" created.',
        'updated' => 'Organization saved.',
        'deleted' => 'Organization ":name" deleted.',
        'switched' => 'Active organization: :name.',
        'role_changed' => 'Role of :name set to :role.',
        'left' => 'Left organization ":name".',
        'member_removed' => ':name has been removed.',
        'invitation_sent' => 'Invitation sent to :email.',
        'invitation_role_changed' => 'Invitation to :email: role set to :role.',
        'invitation_withdrawn' => 'Invitation to :email withdrawn.',
    ],

];
