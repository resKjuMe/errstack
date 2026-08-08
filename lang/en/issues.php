<?php

// The issue list (app/Http/Controllers/IssueController,
// resources/js/shell/pages/issues).
return [

    'title' => 'Issues',
    'help' => 'Every issue of the selected projects, with how often it happened, '
        .'how many users it affected and how it developed. An entry stands for an '
        .'issue, not for a single report: what is counted is how often it '
        .'occurred. The period in the filter bar decides which issues show up — '
        .'every issue that occurred within it.',

    'list' => [
        'untitled' => 'Untitled',
        'empty' => 'No issues in the selected period.',
        'empty_hint' => 'As soon as a report comes in, it shows up here.',
        'count' => ':count issues',
        'first_seen' => 'First: :value',
    ],

    'columns' => [
        'issue' => 'Issue',
        'trend' => 'Trend',
        'events' => 'Events',
        'users' => 'Users',
        'last_seen' => 'Last seen',
    ],

    // The values of App\Enums\IssueSort.
    'sort' => [
        'last_seen' => 'Last seen',
        'first_seen' => 'First seen',
        'times_seen' => 'Events',
        'priority' => 'Priority',
    ],

    'filter' => [
        'sort' => 'Sort by',
        'status' => 'Status',
        'any_status' => 'All',
    ],

    'trend' => [
        'label' => 'Trend, :period',
    ],

    'selection' => [
        'row' => 'Select issue',
        'page' => 'Select all on this page',
        'selected' => ':count selected',
        'select_all' => 'Select all :count issues',
        'all_selected' => 'All :count issues of this selection are selected.',
        'clear' => 'Clear selection',
        'no_actions' => 'Bulk actions arrive with one of the next tasks.',
    ],

    'live' => [
        'new_one' => 'One new issue',
        'new_many' => ':count new issues',
        'show' => 'Show',
    ],

    'environment_ignored' => 'The selected environment does not narrow this list: '
        .'an issue is counted across all environments.',

    // The detail page (app/Http/Controllers/IssueDetailController,
    // resources/js/shell/pages/issues/Show.jsx and issues/detail/*).
    'detail' => [

        'help' => 'This page shows a single report of this issue with everything '
            .'needed to diagnose it: the stack trace, the last steps before it and '
            .'the technical context. How often it happened, how many users it '
            .'affected and the two timestamps apply to the issue as a whole. The '
            .'buttons above move between the reports.',

        'no_event' => 'No report is left for this issue.',
        'no_event_hint' => 'The counters stay; the individual reports may have been '
            .'cleaned up.',

        'nav' => [
            'label' => 'Report',
            'newest' => 'Newest',
            'newer' => 'Newer',
            'older' => 'Older',
            'oldest' => 'Oldest',
        ],

        'header' => [
            'times_seen' => 'Events',
            'users_seen' => 'Users',
            'first_seen' => 'First seen',
            'last_seen' => 'Last seen',
            'status' => 'Status',
            'priority' => 'Priority',
        ],

        'meta' => [
            'title' => 'Report',
            'event_id' => 'Event ID',
            'occurred_at' => 'Occurred',
            'received_at' => 'Received',
            'level' => 'Level',
            'platform' => 'Platform',
            'environment' => 'Environment',
            'release' => 'Release',
            'dist' => 'Dist',
            'server_name' => 'Server',
            'transaction' => 'Transaction',
            'logger' => 'Logger',
            'sdk' => 'SDK',
        ],

        'message' => [
            'title' => 'Message',
        ],

        'exception' => [
            'title' => 'Stack trace',
            'caused_by' => 'Caused by',
            'handled' => 'Handled',
            'unhandled' => 'Unhandled',
            'mechanism' => 'Origin: :type',
        ],

        'frames' => [
            'empty' => 'No stack trace was sent.',
            'line' => 'Line :line',
            'column' => 'Column :column',
            'in_app' => 'Application code',
            'unknown_file' => 'Unknown location',
            'hidden_one' => 'One external frame',
            'hidden_many' => ':count external frames',
            'show' => 'Show',
            'hide' => 'Hide',
            'vars' => 'Variables',
            'toggle' => 'Expand and collapse frame',
        ],

        'breadcrumbs' => [
            'title' => 'Last steps',
            'description' => 'What happened before the error — oldest step first.',
            'data' => 'Data',
        ],

        'sections' => [
            'request' => 'Request',
            'user' => 'User',
            'contexts' => 'Context',
            'tags' => 'Tags',
            'extra' => 'Extra',
            'modules' => 'Packages',
        ],

        'notes' => [
            'title' => 'This report was reduced.',
            'truncated' => 'Truncated: :paths',
            'invalid' => 'Dropped: :paths',
        ],

        'raw' => [
            'title' => 'Raw data',
            'description' => 'The parsed report and next to it what the SDK sent.',
            'show' => 'Show',
            'hide' => 'Hide',
            'open' => 'Open in a new tab',
            'loading' => 'Loading …',
            'failed' => 'The raw data could not be loaded.',
        ],

    ],

];
