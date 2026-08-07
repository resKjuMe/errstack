<?php

// Labels for the enumerations in app/Enums. One key per case, named after the
// stored value so the reference stays readable in exports as well.
return [

    'api_scope' => [
        'org:read' => 'Read organization',
        'org:write' => 'Change organization',
        'member:read' => 'Read members',
        'member:write' => 'Manage members',
        'team:read' => 'Read teams',
        'team:write' => 'Manage teams',
        'project:read' => 'Read projects',
        'project:write' => 'Change projects',
        'project:admin' => 'Manage projects',
        'event:read' => 'Read events',
        'event:write' => 'Submit events',
        'issue:read' => 'Read issues',
        'issue:write' => 'Edit issues',
        'alerts:read' => 'Read alerts',
        'alerts:write' => 'Manage alerts',
    ],

    'api_scope_group' => [
        'org' => 'Organization',
        'member' => 'Members',
        'team' => 'Teams',
        'project' => 'Projects',
        'event' => 'Events',
        'issue' => 'Issues',
        'alerts' => 'Alerts',
        'other' => 'Other',
    ],

    'api_token_kind' => [
        'personal' => 'Personal',
        'organization' => 'Organization-wide',
    ],

    'api_token_kind_description' => [
        'personal' => 'Acts on your behalf and ends with your membership.',
        'organization' => 'Belongs to the organization and works independently of individual accounts.',
    ],

    'audit_action' => [
        'organization_created' => 'Organization created',
        'organization_updated' => 'Organization changed',
        'invitation_sent' => 'Invitation sent',
        'invitation_role_changed' => 'Invitation role changed',
        'invitation_revoked' => 'Invitation revoked',
        'invitation_accepted' => 'Invitation accepted',
        'membership_role_changed' => 'Role changed',
        'membership_removed' => 'Member removed',
        'membership_left' => 'Left organization',
        'team_created' => 'Team created',
        'team_updated' => 'Team changed',
        'team_deleted' => 'Team deleted',
        'team_member_added' => 'Member added to team',
        'team_member_removed' => 'Member removed from team',
    ],

    'delivery_status' => [
        'pending' => 'in transit',
        'sent' => 'delivered',
        'failed' => 'failed',
    ],

    'filter_period' => [
        '1h' => 'Last hour',
        '24h' => 'Last 24 hours',
        '7d' => 'Last 7 days',
        '14d' => 'Last 14 days',
        '30d' => 'Last 30 days',
        '90d' => 'Last 90 days',
        'custom' => 'Custom range',
    ],

    'ingest_type' => [
        'event' => 'Error report',
    ],

    'notification_event' => [
        'alert' => 'Alerts',
        'assignment' => 'Assignments',
        'mention' => 'Mentions',
        'workflow_change' => 'Workflow changes',
        'deploy' => 'Deploys',
        'weekly_digest' => 'Weekly digest',
        'quota_warning' => 'Quota warnings',
    ],

    'notification_event_description' => [
        'alert' => 'An alert fired — something is broken.',
        'assignment' => 'An issue was assigned to you.',
        'mention' => 'Someone mentioned you in a comment.',
        'workflow_change' => 'An issue was resolved, ignored or reopened.',
        'deploy' => 'A new version has been released.',
        'weekly_digest' => 'The weekly summary of your projects.',
        'quota_warning' => 'The ingest quota is running low.',
    ],

    'notification_level' => [
        'info' => 'Information',
        'warning' => 'Warning',
        'error' => 'Error',
    ],

    'notification_transport' => [
        'mail' => 'Email',
        'in_app' => 'In the application',
    ],

    'notification_transport_description' => [
        'mail' => 'To the email address of this account.',
        'in_app' => 'In the inbox inside Errstack.',
    ],

    'organization_role' => [
        'owner' => 'Owner',
        'admin' => 'Administration',
        'member' => 'Member',
        'viewer' => 'Read-only',
    ],

    'platform' => [
        'php' => 'PHP',
        'javascript' => 'JavaScript',
        'python' => 'Python',
        'node' => 'Node.js',
        'java' => 'Java',
        'go' => 'Go',
        'ruby' => 'Ruby',
        'dotnet' => '.NET',
        'other' => 'Other',
    ],

    'resolution_behavior' => [
        'manual' => 'Resolve manually only',
        'after_week' => 'After 7 days without a new occurrence',
        'after_month' => 'After 30 days without a new occurrence',
    ],

];
