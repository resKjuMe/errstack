<?php

// Profiling (resources/js/shell/pages/profiling, App\Http\Controllers\ProfilingController).
return [

    'title' => 'Profiles',
    'detail_title' => 'Profile',

    'help' => [
        'purpose' => 'A profile shows which functions burned the CPU time of a request — not how long it took, but where the work happened.',
        'aggregate' => 'A single profile may have caught the outlier. Pick a transaction to stack all of its profiles; only then does the pattern show.',
        'self_total' => 'Self time is spent in the function itself, total time includes everything it called. Looking at total time alone always leads to the entry point — it is the largest by definition and says nothing.',
        'gap' => 'CPU time is almost always shorter than response time: waiting on the database, the network or a lock burns none. If the transaction says one second and the profile says 40 ms, it was not the code.',
        'sampling' => 'Measurement works by sampling at fixed intervals. A handful of samples is a hint, not a finding — which is why the count sits next to every function.',
    ],

    'list' => [
        'heading' => 'Recorded profiles',
        'columns' => [
            'transaction' => 'Transaction',
            'started' => 'Time',
            'duration' => 'CPU time',
            'samples' => 'Samples',
            'environment' => 'Environment',
            'release' => 'Release',
        ],
        'open' => 'Open profile',
        'aggregate' => 'All profiles of this transaction',
        'limit' => 'Showing the :limit most recent profiles in this period.',
        'empty' => 'No profiles were reported for these projects in the selected period.',
        'empty_hint' => 'Profiles come from the profiling feature of the SDK in the monitored application, and run there at their own rate below the transaction rate. Until that is set up this list stays empty, even when response times arrive.',
        'empty_transaction' => 'No profiles were reported for this transaction in the selected period.',
    ],

    'aggregate' => [
        'heading' => 'Aggregated: :transaction',
        'hint' => 'Up to :limit profiles from this period are stacked.',
        'profiles' => ':count profiles · :samples samples',
        'release' => 'Release',
        'all_releases' => 'All releases',
        'compare' => 'Compare with',
        'no_compare' => 'No comparison',
        'clear' => 'Clear selection',
        'empty' => 'There are no profiles for this selection.',
    ],

    'flamegraph' => [
        'heading' => 'Flame graph',
        'search' => 'Find function',
        'search_placeholder' => 'Part of a function or file name',
        'matches' => ':count matches · :share of CPU time',
        'no_matches' => 'No match',
        'reset' => 'Show the whole tree',
        'zoomed' => 'Zoomed in: :function',
        'zoom' => 'Zoom into this branch',
        'collapse' => 'Collapse branch',
        'expand' => 'Expand branch',
        'collapsed' => ':count collapsed',
        'self' => 'Self',
        'total' => 'Total',
        'samples' => 'Samples',
        'unknown_frame' => 'unknown function',
        'empty' => 'This profile contains no usable samples.',
        'incomplete' => ':dropped paths were cut during ingest, :pruned branches below a thousandth of the time are not drawn.',
    ],

    'functions' => [
        'heading' => 'Functions',
        'columns' => [
            'function' => 'Function',
            'self' => 'Self time',
            'total' => 'Total time',
            'samples' => 'Samples',
        ],
        'sort' => [
            'ascending' => 'Sort ascending by :column',
            'descending' => 'Sort descending by :column',
        ],
        'in_app' => 'application code',
        'limit' => 'Showing the :limit functions with the largest self time out of :total.',
        'empty' => 'No function matches this search.',
    ],

    'comparison' => [
        'heading' => 'Comparison: :baseline against :candidate',
        'hint' => 'Shares of CPU time are compared, not times: the number of profiles differs per release, which would make absolute values incomparable.',
        'columns' => [
            'function' => 'Function',
            'baseline' => ':release',
            'candidate' => ':release',
            'delta' => 'Change',
        ],
        'empty' => 'One of the two releases has no profiles in this period.',
    ],

    'profile' => [
        'transaction' => 'Transaction',
        'thread' => 'Thread',
        'platform' => 'Platform',
        'started' => 'Time',
        'cpu' => 'CPU time',
        'wall' => 'Response time',
        'samples' => 'Samples',
        'release' => 'Release',
        'environment' => 'Environment',
        'aggregate_link' => 'View all profiles of this transaction',
    ],

    'units' => [
        'microseconds' => 'µs',
        'milliseconds' => 'ms',
        'seconds' => 's',
    ],

];
