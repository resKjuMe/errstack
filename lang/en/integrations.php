<?php

// Provider integrations (X1) — App\Http\Controllers\IntegrationController,
// App\Http\Controllers\GitHubIntegrationController,
// resources/js/shell/pages/integrations and
// resources/js/shell/pages/issues/detail/ExternalIssues.jsx.
return [

    'title' => 'Integrations',
    'help' => 'Where this organization keeps its code — and what arrives from there '
        .'on its own: the commits of a release, without a pipeline having to hand '
        .'them over. It also lets you turn an issue into a ticket, and when that '
        .'ticket is closed, the issue counts as resolved.',

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

    'flash' => [
        'connected' => 'Connected to GitHub.',
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
        'no_token' => 'No access token is stored for this integration.',
        'invalid_repository' => 'The repository name is unusable.',
        'unexpected_response' => 'GitHub answered unexpectedly.',
        'http_status' => 'GitHub answered with status :status.',
        'token_exchange' => 'GitHub did not hand out an access token.',
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
        ],
    ],

];
