<?php

// Performance issues (app/Http/Controllers/PerformanceIssueController,
// app/Http/Controllers/ProjectPerformanceController,
// resources/js/shell/pages/performance).
return [

    'title' => 'Performance issues',
    'help' => 'Patterns the detection found in stored traces — N+1 queries, duplicate '
        .'queries, slow calls, blocking resources. An entry stands for one pattern in '
        .'one place, not for a single request: it counts how often the pattern occurred '
        .'and sums up how much time it cost. Errors live separately, in the issue list.',

    'list' => [
        'untitled' => 'Untitled',
        'empty' => 'No performance issues in the selected period.',
        'empty_hint' => 'Detection runs in the background over stored traces. As soon '
            .'as a pattern crosses a threshold, it shows up here.',
        'count' => ':count performance issues',
        'first_seen' => 'First: :value',
        'per_event' => ':value per occurrence',
    ],

    'columns' => [
        'problem' => 'Problem',
        'trend' => 'Trend',
        'time_lost' => 'Time lost',
        'events' => 'Occurrences',
        'users' => 'Users',
        'last_seen' => 'Last seen',
    ],

    // The values of App\Enums\PerformanceIssueSort.
    'sort' => [
        'time_lost' => 'Time lost',
        'times_seen' => 'Occurrences',
        'last_seen' => 'Last seen',
        'first_seen' => 'First seen',
    ],

    'filter' => [
        'sort' => 'Sort by',
        'status' => 'Status',
        'problem' => 'Pattern',
        'any_problem' => 'All patterns',
        'environment_ignored' => 'The counters of an entry span all environments — the '
            .'selected environment does not affect this list.',
    ],

    'detail' => [
        'back' => 'Back to the list',
        'examples' => 'Examples',
        'examples_hint' => 'The most expensive occurrences of this pattern, with the '
            .'affected spans of the trace.',
        'no_examples' => 'No evidence left for this entry — the traces have aged out of '
            .'retention.',
        'trace' => 'Trace',
        'transaction' => 'Transaction',
        'occurred_at' => 'Occurred',
        'time_lost' => 'Time lost',
        'spans' => 'Affected spans',
        'span_count' => ':count spans',
        'total_time_lost' => 'Total time lost',
        'time_lost_per_event' => 'Per occurrence',
        'times_seen' => 'Occurrences',
        'users_seen' => 'Affected users',
        'first_seen' => 'First seen',
        'last_seen' => 'Last seen',
    ],

    'evidence' => [
        'repeats' => 'Repetitions',
        'total_us' => 'Total duration',
        'longest_us' => 'Longest of them',
        'duration_us' => 'Duration',
        'threshold_us' => 'Threshold',
        'blocking_us' => 'Blocking',
        'source_description' => 'Source query',
        'encoded_bytes' => 'Transferred',
        'decoded_bytes' => 'Decoded',
        'uncompressed' => 'Uncompressed',
        'misses' => 'Misses',
        'hits' => 'Hits',
        'method' => 'Method',
        'status' => 'Status',
        'resource_op' => 'Kind',
        'yes' => 'Yes',
        'no' => 'No',
    ],

    'settings' => [
        'title' => 'Performance detection',
        'help' => 'When a pattern counts as a problem. Detection runs in the background '
            .'over this project\'s stored traces; the thresholds decide what ends up in '
            .'the list. A disabled pattern is no longer looked for — entries already '
            .'detected stay.',
        'enabled' => 'Detection active',
        'default_hint' => 'Default: :value',
        'changed_hint' => 'Differs from the default (:value)',
        'save' => 'Save thresholds',
        'issues_link' => 'View detected performance issues',
        'read_only' => 'You do not have permission to change these thresholds.',
    ],

    'flash' => [
        'settings_updated' => 'The performance detection thresholds have been saved.',
    ],

];
