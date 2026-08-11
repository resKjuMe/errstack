<?php

// Provider integrations (X1, X4) — App\Http\Controllers\IntegrationController,
// App\Http\Controllers\GitHubIntegrationController,
// App\Http\Controllers\TicketIntegrationController,
// resources/js/shell/pages/integrations and
// resources/js/shell/pages/issues/detail/ExternalIssues.jsx.
return [

    'title' => 'Integrations',
    'help' => 'Where this organization keeps its code and where it tracks its '
        .'tickets. Commits arrive from the code on their own, without a pipeline '
        .'having to hand them over; an issue can become a ticket, and its state is '
        .'kept in sync in both directions — each direction separately switchable.',

    'empty' => 'Not connected yet. Once connected, you pick which repositories supply '
        .'this organization.',

    'not_configured' => [
        'title' => 'Not set up for this installation',
        'hint' => 'The OAuth app credentials are missing (GITHUB_CLIENT_ID and '
            .'GITHUB_CLIENT_SECRET). Without them, connecting ends on a GitHub error '
            .'page — the rest of the application is unaffected.',
    ],

    'lost' => [
        'title' => 'Connection lost',
        'hint' => 'GitHub is rejecting the access. Until it is renewed, no commits '
            .'arrive and no tickets can be created. The most common reason: access '
            .'was revoked, or the authorization expired.',
    ],

    'fields' => [
        'account' => 'Account',
        'status' => 'State',
        'connected_at' => 'Connected',
        'connected_by' => ':at by :name',
        'last_synced_at' => 'Last fetched',
        'never' => 'Never',
    ],

    'actions' => [
        'connect' => 'Connect to GitHub',
        'reconnect' => 'Reconnect',
        'disconnect' => 'Disconnect',
        'disconnect_confirm' => 'Disconnect the integration? The repositories and '
            .'their commits stay — nothing new will be fetched, and tickets can no '
            .'longer be created.',
    ],

    'repositories' => [
        'title' => 'Supplying repositories',
        'hint' => 'Commits are fetched from these repositories, and tickets are '
            .'created in them.',
        'empty' => 'None selected yet.',
        'manage' => 'View all repositories of this organization',
        'add' => 'Connect repository',
        'add_hint' => 'The list is fetched from GitHub on request only — this page '
            .'should load even when GitHub does not answer.',
        'load' => 'Load choices',
        'loading' => 'Loading …',
        'load_failed' => 'The choices could not be loaded.',
        'choose' => 'Pick a repository …',
        'connect' => 'Connect',
    ],

    'issue' => [
        'title' => 'Provider ticket',
        'description' => 'What is being worked on — and what happens when it is '
            .'closed over there: the issue then counts as resolved.',
        'hint' => 'Without a number a new ticket is created. With a number an '
            .'existing one is linked.',
        'no_repositories' => 'No repository is connected through the integration for '
            .'this organization yet.',
        'provider' => 'Provider',

        'fields' => [
            'number' => 'Number',
            'number_placeholder' => 'No.',
        ],

        'actions' => [
            'create' => 'Create ticket',
            'link' => 'Link',
            'unlink' => 'Unlink',
        ],

        // The body of a newly created ticket. Deliberately short: what counts is
        // the link back — everything else here is a copy, and copies age while
        // the page behind the link stays current.
        'body' => [
            'culprit' => 'Culprit',
            'project' => 'Project',
            'times_seen' => 'Times seen',
            'first_seen' => 'First seen',
            'link' => 'The issue in Errstack: :url',
        ],
    ],

    // The ticket systems (X4).
    'ticket' => [
        'empty' => 'Not connected yet. Connecting takes an API token — it belongs '
            .'to this organization and is stored encrypted.',
        'docs' => 'Where do I get a token?',

        'fields' => [
            'token' => 'API token',
            'base_url' => 'Instance address',
            'base_url_placeholder' => 'https://acme.atlassian.net',
            'email' => 'Email address of the token',
            'target' => 'Project',
            'default_project' => 'Project (default)',
            'default_type' => 'Issue type',
            'default_priority' => 'Priority',
            'default_assignee' => 'Assignee (provider id)',
        ],

        'hints' => [
            'base_url' => 'The address of your Jira instance, without a path.',
            'email' => 'The address of the account that created the token. Jira '
                .'Cloud requires both together.',
            'token' => 'Checked before it is stored — and never shown again '
                .'afterwards.',
            'default_type' => 'The issue type of new tickets. Empty means “Task”; '
                .'only relevant for Jira.',
            'default_priority' => 'For Jira the name (“High”), for Linear the '
                .'number (0–4). Empty means: do not send it.',
            'default_assignee' => 'The id of the account at the provider. There is '
                .'no mapping between the two user directories — this is a fixed '
                .'default, not a translation.',
        ],

        'sync' => [
            'title' => 'Status sync',
            'hint' => 'Each direction switchable on its own. The outbound direction '
                .'writes in someone else’s system — which is why it does not share '
                .'a switch with the other one.',
            'inbound' => 'Ticket resolved → issue resolved',
            'inbound_hint' => 'A reopened ticket does not reopen the issue by '
                .'itself: “resolved” here may rest on a second ticket or on a '
                .'release.',
            'outbound' => 'Issue resolved → ticket resolved',
            'outbound_hint' => 'If the issue is reopened here, the ticket takes the '
                .'same way back.',
        ],

        'defaults' => [
            'title' => 'Defaults for new tickets',
            'hint' => 'What a new ticket starts with. None of it is validated '
                .'against the provider — a project that does not exist reports '
                .'itself on the first attempt, in its own words.',
        ],

        'webhook' => [
            'title' => 'Callback address',
            'hint' => 'Register this address as a webhook at the provider so a '
                .'closed ticket arrives here. It contains a secret: treat it like a '
                .'password.',
            'why' => 'Why a secret in the address rather than a signature? Jira '
                .'Cloud does not sign a callback registered this way, and Linear’s '
                .'signature rests on a secret that only comes into existence when '
                .'the webhook is set up over there.',
            'rotate' => 'Renew address',
            'rotate_confirm' => 'Renew the address? The old one stops answering — it '
                .'has to be replaced at the provider.',
        ],

        'actions' => [
            'connect' => 'Connect',
            'reconnect' => 'Replace token',
            'save' => 'Save',
        ],

        'targets' => [
            'load' => 'Load choices',
            'loading' => 'Loading …',
            'choose' => 'Choose a project …',
            'load_failed' => 'The choices could not be loaded.',
        ],
    ],

    'flash' => [
        'connected' => 'Connected to GitHub.',
        'ticket_connected' => 'Connected to :provider (:account).',
        'settings_saved' => 'The settings are saved.',
        'webhook_rotated' => 'The callback address is renewed. It has to be '
            .'replaced at the provider.',
        'disconnected' => 'Integration disconnected.',
        'aborted' => 'Connecting was cancelled.',
        'failed' => 'Connecting failed: :reason',
        'state_mismatch' => 'Connecting timed out. Please try again.',
        'not_configured' => 'No GitHub app is set up for this installation.',
        'repository_connected' => 'Repository :name connected.',
        'issue_linked' => 'Linked to :reference.',
        'issue_unlinked' => 'Link removed.',
    ],

    'errors' => [
        'not_connected' => 'There is no working GitHub integration.',
        'ticket_not_connected' => 'There is no working :provider integration.',
        'no_token' => 'No access token is stored for this integration.',
        'invalid_repository' => 'The repository name is unusable.',
        'unexpected_response' => ':provider answered unexpectedly.',
        'http_status' => ':provider answered with status :status.',
        'token_exchange' => 'GitHub did not hand out an access token.',
        'unsupported_provider' => 'This provider is not supported.',

        // Domain errors of the ticket systems (X4). They are listed one by one
        // rather than folded into a friendlier catch-all, because each of them
        // calls for a different action — and “that did not work” tells nobody
        // what to do.
        'invalid_number' => 'The number does not match the selected project.',
        'ticket_not_found' => 'There is no ticket :reference.',
        'not_created' => ':provider did not create the ticket.',
        'not_updated' => 'Ticket :reference could not be changed.',
        'no_external_id' => 'No provider id is stored for :reference — the link '
            .'cannot be synced.',
        'no_transition' => 'The workflow of :reference does not allow this change.',
        'no_state' => 'Team :team has no matching state.',
        'unknown_target' => 'Project :target does not exist over there.',
    ],

    // What happened to an incoming event. It sits on the event and answers
    // “why did nothing happen?” — in practice the most common outcome is
    // “arrived, matches nothing”.
    'webhook' => [
        'results' => [
            'ignored' => 'Not evaluated (:event).',
            'unmatched' => 'No match possible.',
            'unlinked' => 'No issue linked to this ticket.',
            'issue' => ':links link(s) updated, :resolved resolved.',
            'push' => ':releases release(s) will fetch their commits.',
            'disconnected' => 'The integration was disconnected — nothing to do.',
            'inbound_off' => 'Syncing from there to here is switched off.',
        ],
    ],

];
