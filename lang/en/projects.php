<?php

// Project pages (resources/js/shell/pages/projects) and the messages of the
// corresponding controllers.
return [

    'index' => [
        'title' => 'Projects',
        'help' => 'A project stands for exactly one monitored application. Error reports will later arrive through the project\'s security token; the platform determines which SDK to set up for it.',
        'no_organization_title' => 'No organization yet',
        'no_organization_description' => 'Projects always belong to an organization. Create one first.',
        'to_organizations' => 'Go to organizations',
        'empty_title' => 'No projects yet',
        'empty_can_create' => 'Create one so error reports have somewhere to go.',
        'empty_read_only' => 'The administrators of this organization create projects.',
    ],

    'create' => [
        'title' => 'New project',
        'description' => 'Will be created in ":organization". The settings can be changed afterwards.',
        'name' => 'Name',
        'platform' => 'Platform',
        'submit' => 'Create',
    ],

    'show' => [
        'help' => 'The settings apply to everything recorded for this project: the environment is the default for reports that do not name one, the resolution behaviour closes quiet issues by itself, and the retention determines how long events are kept.',
        'all_projects' => 'All projects',
    ],

    'settings' => [
        'title' => 'Settings',
        'description' => 'The slug in the address bar stays unchanged when renaming, so shared links keep working.',
        'read_only_description' => 'Only the administrators of the organization may change them.',
        'name' => 'Name',
        'platform' => 'Platform',
        'default_environment' => 'Default environment',
        'default_environment_hint' => 'Applies to reports that do not send an environment of their own.',
        'retention' => 'Data retention (days)',
        'retention_label' => 'Data retention',
        'retention_value' => ':days days',
        'resolution' => 'Resolution behaviour',
        'submit' => 'Save',
    ],

    'teams' => [
        'title' => 'Responsible teams',
        'description' => 'Without an assignment the project is the whole organization\'s business.',
        'empty' => 'This organization does not have any teams yet.',
        'submit' => 'Save',
    ],

    'environments' => [
        'title' => 'Environments',
        'description' => 'Recorded when the first report arrives. Hidden environments no longer appear in the filter bar.',
        'empty' => 'No report has arrived for this project yet.',
        'hidden' => 'hidden',
        'last_seen' => 'Last reported: :time',
        'show' => 'Offer again',
        'hide' => 'Hide',
    ],

    'keys' => [
        'title' => 'Client keys',
        'description' => 'The DSN is the address the SDK sends its reports to. It is listed with all keys of this project on a page of its own.',
        'manage' => 'Manage client keys',
    ],

    'crons' => [
        'title' => 'Cron jobs',
        'description' => 'Monitored cron jobs check in on every run. If the check-in fails to arrive, a message goes out — instead of the gap only showing up as missing data.',
        'manage' => 'View cron jobs',
    ],

    'alerts' => [
        'title' => 'Alerts',
        'description' => 'Threshold alerts on metrics: error count, failure rate, throughput and response times. They speak up when a metric leaves its range — and again once it is back.',
        'manage' => 'View alerts',
    ],

    'issue_alerts' => [
        'title' => 'Alert rules',
        'description' => 'Who gets notified when: new issues, regressions, escalations and frequencies — narrowed down by level, environment, release or tag, with a rate limit against notification floods.',
        'manage' => 'View alert rules',
    ],

    'alert_overview' => [
        'title' => 'Alert overview',
        'description' => 'What fired when: every rule of both kinds with state, last trigger and history — plus the option to snooze a rule for a while without stopping its evaluation.',
        'manage' => 'View history',
    ],

    'sampling' => [
        'title' => 'Sampling',
        'description' => 'Only a configurable share of the response times is stored; the evaluations scale it back up. Error reports are untouched by this.',
        'manage' => 'View rules',
    ],

    'performance' => [
        'title' => 'Performance detection',
        'description' => 'When N+1 queries, slow calls or blocking resources count as a performance issue. Detection runs in the background over stored traces.',
        'manage' => 'View thresholds',
    ],

    'grouping' => [
        'title' => 'Grouping',
        'description' => 'Similar reports are folded into a single entry. Where that turns out too coarse or too fine, project rules correct it.',
        'manage' => 'View rules',
    ],

    'filters' => [
        'title' => 'Inbound filters',
        'description' => 'Known noise — browser extensions, crawlers, local development — is discarded on arrival and only counted.',
        'manage' => 'Configure filters',
    ],

    'privacy' => [
        'title' => 'Privacy',
        'description' => 'Passwords, credentials and card numbers are removed from every report before anything is stored. Whatever should disappear on top of that has its own page.',
        'manage' => 'Configure privacy',
    ],

    'delete' => [
        'title' => 'Delete project',
        'description' => 'Deleting the project removes its settings, the team assignment and all attached data — irreversibly.',
        'submit' => 'Delete project',
    ],

    'flash' => [
        'created' => 'Project ":name" created.',
        'updated' => 'Project saved.',
        'deleted' => 'Project ":name" deleted.',
        'teams_updated' => 'Responsible teams saved.',
        'environment_shown' => 'Environment ":name" is offered again.',
        'environment_hidden' => 'Environment ":name" is no longer offered.',
    ],

];
