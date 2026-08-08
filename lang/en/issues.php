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
        'search' => 'Search',
        'search_placeholder' => 'e.g. release:1.1.0 or firstRelease:1.0.0',
        'search_unsupported' => 'Not applied: :terms. The full search syntax arrives with '
            .'one of the next tasks; until then only release: and firstRelease: take effect.',
    ],

    // The affected versions on a row of the list.
    'release' => [
        'first' => 'First in',
        'last' => 'Last in',
        'only' => 'In',
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

];
