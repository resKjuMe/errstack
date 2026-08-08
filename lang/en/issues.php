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
        'search_placeholder' => 'e.g. is:unresolved browser:Chrome timesSeen:>100',
        'search_hint' => 'field:value, several terms are an AND. `!` negates, '
            .'`or` and brackets combine, `*` stands for any characters.',
        'search_error' => 'The search expression was not understood: :message '
            .'The unfiltered list is shown instead.',
        'search_error_at' => 'at this spot: :excerpt',
        'search_unavailable' => 'Not applicable yet: :terms. These terms belong to the search '
            .'language, but the data behind them arrives with a later task — they do not '
            .'narrow the list.',
        'search_suggestions' => 'Suggestions',
    ],

    // The built-in views (S5): App\Support\Issues\IssueViews.
    'views' => [
        'unresolved' => 'Unresolved',
        'for_review' => 'For review',
        'regressed' => 'Regressed',
        'assigned' => 'Assigned to me',
        'new_24h' => 'New (24 hours)',
        'ignored' => 'Ignored',
    ],

    // The saved searches (S5): App\Http\Controllers\SavedSearchController,
    // resources/js/shell/pages/issues/SavedSearches.jsx.
    'saved' => [

        'title' => 'Views',
        'views' => 'Built-in views',
        'own' => 'Saved searches',
        'empty' => 'No saved search yet.',
        'unavailable' => 'This view cannot be answered in full yet.',
        'shared_by' => 'shared by :name',
        'default_badge' => 'Default',
        'manage' => 'Manage',
        'close' => 'Close',

        'save' => 'Save search',
        'save_hint' => 'Saved are the search text and the sort order — not the period, '
            .'the project selection or the environment. Those stay as the filter bar shows them.',
        'name' => 'Name',
        'name_placeholder' => 'e.g. Critical unresolved errors',
        'query' => 'Search text',
        'sort' => 'Sort by',
        'shared' => 'Share with the organization',
        'shared_hint' => 'Shared searches are visible to everyone in this organization. '
            .'Only you can change or delete them.',
        'submit' => 'Save',
        'cancel' => 'Cancel',

        'rename' => 'Rename',
        'delete' => 'Delete',
        'confirm_delete' => 'Delete this saved search?',
        'set_default' => 'Default for :project',
        'clear_default' => 'No longer default for :project',
        'default_hint' => 'The issue list opens with this search when only :project is '
            .'selected. For you only.',

        'flash' => [
            'created' => 'The search was saved.',
            'updated' => 'The search was changed.',
            'deleted' => 'The search was deleted.',
            'default_set' => 'This search is now your default for :project.',
            'default_cleared' => 'The default was cleared.',
        ],

        'errors' => [
            'too_many' => 'More than :limit saved searches per organization are not '
                .'provided for. Delete one you no longer need.',
        ],
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
        'no_actions' => 'No action available.',
    ],

    // The state actions (S6): App\Http\Controllers\IssueActionController,
    // resources/js/shell/pages/issues/IssueActions.jsx.
    'actions' => [

        'title' => 'Actions',
        'menu' => 'More actions',
        'selected' => 'Action on :count selected issues',

        'resolve' => 'Resolve',
        'unresolve' => 'Reopen',
        'ignore' => 'Ignore',
        'bookmark' => 'Bookmark',
        'unbookmark' => 'Remove bookmark',
        'subscribe' => 'Subscribe',
        'unsubscribe' => 'Unsubscribe',
        'delete' => 'Delete',
        'discard' => 'Delete and discard from now on',

        'apply' => 'Apply',
        'cancel' => 'Cancel',
        'threshold' => 'Count',

        'window' => [
            'label' => 'Time window',
            'none' => 'Without a time window',
            'hour' => 'within an hour',
            'day' => 'within a day',
            'week' => 'within a week',
        ],

        // An ignore condition in words — it appears in the message, in the
        // header of the detail page and in the history.
        'condition' => [
            'count' => 'until :count more events',
            'count_window' => 'until :count more events within :minutes minutes',
            'users' => 'until :count more affected users',
        ],

        'ignored_state' => 'Ignored :condition — :done of :total.',
        'resolved_in' => 'Resolved in release :release.',
        'resolved_next' => 'Resolved with the next release.',

        'confirm' => [
            'delete' => 'Delete these issues? The counters are gone afterwards. If the '
                .'error happens again, a new issue is created.',
            'discard' => 'Delete these issues and discard future reports? Reports of '
                .'the same kind will no longer be accepted until discarding is '
                .'lifted.',
        ],

        'undo' => [
            'default' => 'Undo',
            // For a deletion the way back only lifts the discarding — the issue
            // itself is gone. The label says so instead of promising "undo" and
            // doing half of it.
            'discard' => 'Stop discarding',
        ],

        'flash' => [
            'resolved' => ':count issues resolved (:condition).',
            'unresolve' => ':count issues reopened.',
            'ignored' => ':count issues ignored (:condition).',
            'bookmark' => ':count issues bookmarked.',
            'unbookmark' => 'Bookmark removed for :count issues.',
            'subscribe' => 'Subscribed to :count issues.',
            'unsubscribe' => 'Unsubscribed from :count issues.',
            'delete' => ':count issues deleted.',
            'discard' => ':count issues deleted; reports of the same kind will be '
                .'discarded from now on.',
            'none' => 'No issue affected — the selection is gone by now.',
            'undone' => 'The action has been undone.',
            'undo_expired' => 'This can no longer be undone.',
        ],

        'validation' => [
            'no_target' => 'No issue selected.',
            'mode' => 'Please choose a condition.',
            'count' => 'Please enter a count.',
            'window' => 'A time window only applies to an event threshold.',
        ],

    ],

    // The activity history of an issue
    // (App\Support\Issues\IssueActivityFeed).
    'activity' => [
        'title' => 'History',
        'empty' => 'Nothing has happened yet.',
        'by' => 'by :actor',
        'system' => 'automatic',

        'resolved' => 'Resolved (:condition)',
        'unresolved' => 'Reopened',
        'ignored' => 'Ignored (:condition)',
        'ignore_expired' => 'Ignoring ended — the condition was met',
        'bookmarked' => 'Bookmarked',
        'unbookmarked' => 'Bookmark removed',
        'subscribed' => 'Subscribed',
        'unsubscribed' => 'Unsubscribed',
        'deleted' => 'Deleted',
        'discarded' => 'Deleted; reports of the same kind will be discarded from now on',
    ],

    // Comments on an issue (App\Support\Issues\IssueComments,
    // resources/js/shell/pages/issues/detail/Comments.jsx).
    'comments' => [
        'title' => 'Write a comment',
        'placeholder' => 'What is there to say about this issue? Use @ to mention a person or a team.',
        'hint' => 'Use @ to mention a person or a team — everyone mentioned is notified.',
        'submit' => 'Comment',
        'edited' => 'edited',
        'edited_at' => 'edited on :at',
        'edit' => 'Edit',
        'save' => 'Save',
        'cancel' => 'Cancel',
        'delete' => 'Delete',
        'delete_confirm' => 'Delete this comment? This cannot be undone.',
        'no_suggestions' => 'Nobody found.',

        // How the suggestion list shows what is being mentioned.
        'kind' => [
            'user' => 'Person',
            'team' => 'Team',
        ],

        'flash' => [
            'created' => 'Comment written.',
            'updated' => 'Comment changed.',
            'deleted' => 'Comment deleted.',
        ],

        'notification' => [
            'title' => ':actor mentioned you in :project',
            'context_project' => 'Project',
            'context_issue' => 'Issue',
        ],
    ],

    // Merging and splitting issues by hand (S9,
    // app/Support/Issues/IssueMerging).
    'merge' => [
        'action' => 'Merge :count issues',
        'hint' => 'The selected issues become one. The one seen most often becomes the '
            .'head, the others become subgroups and can be split off again one by one. '
            .'No event is lost in the process.',
        'merged_into' => 'This issue is a subgroup of',

        'badge' => [
            'label' => ':count merged',
            'hint' => 'This issue was merged by hand from several. The figures cover all '
                .'subgroups together.',
        ],

        'sources' => [
            'title' => 'Subgroups (:count)',
            'description' => 'Issues merged by hand. Their figures are the ones from the '
                .'merge — whatever happened afterwards counts towards the issue above.',
            'figures' => ':count times · :first to :last',
        ],

        'split' => [
            'action' => 'Split off',
            'hint' => 'This subgroup returns to the list as an issue of its own, with the '
                .'figures it had when it was merged.',
        ],

        'error' => [
            'mixed_projects' => 'Issues can only be merged within a single project.',
            'only_errors' => 'Only errors can be merged, not performance issues.',
            'already_merged' => 'At least one of the selected issues is already a '
                .'subgroup. It has to be split off first.',
        ],

        'flash' => [
            'merged' => ':count issues merged into one.',
            'unmerged' => 'Subgroup split off again.',
        ],
    ],

    'live' => [
        'new_one' => 'One new issue',
        'new_many' => ':count new issues',
        'show' => 'Show',
    ],

    'environment_ignored' => 'The selected environment does not narrow this list: '
        .'an issue is counted across all environments. To see the issues of one '
        .'environment only, search for environment:production.',

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

        'symbolication' => [
            'pending' => 'Translating the stack trace using source maps …',
            'counted' => ':mapped of :total frames translated',
            'show_minified' => 'Show minified version',
            'show_source' => 'Show original source',
            'frame_count' => '(:count×)',
            'from' => 'Reported as',
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
