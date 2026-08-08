<?php

// Performance overview (resources/js/shell/pages/Performance.jsx,
// App\Http\Controllers\PerformanceController).
return [

    'title' => 'Performance',

    'help' => [
        'purpose' => 'The list shows every page and endpoint that reported response times in the selected period — sorted by the biggest problem.',
        'percentiles' => 'p95 means: 95 out of 100 calls were faster. The average hides exactly the outliers you came here to find.',
        'sampling' => 'Throughput is extrapolated to the real number of calls if the project uses sampling. Response times and failure rate are not extrapolated — they can be estimated from a sample without bias.',
        'trend' => 'The trend arrow compares p95 with the equally long period before. Below five measurements per side it stays away, otherwise a single outlier would signal a regression.',
        'search' => 'The search matches name and operation. Several terms are combined with AND; “op:http.server” narrows it down to one operation.',
    ],

    'columns' => [
        'name' => 'Transaction',
        'throughput' => 'Throughput',
        'p50' => 'p50',
        'p75' => 'p75',
        'p95' => 'p95',
        'p99' => 'p99',
        'avg' => 'Average',
        'failureRate' => 'Failure rate',
        'users' => 'Users',
        'userMisery' => 'Miserable',
        'count' => 'Measurements',
        'trend' => 'Trend',
    ],

    'search' => [
        'label' => 'Search',
        'placeholder' => 'Name or op:http.server',
        'submit' => 'Search',
        'clear' => 'Clear search',
    ],

    'sort' => [
        'ascending' => 'Sort by :column ascending',
        'descending' => 'Sort by :column descending',
    ],

    'row' => [
        'no_op' => 'without operation',
        'measurements' => ':measured of :extrapolated extrapolated calls measured',
        'users' => ':miserable of :users users had to wait too long',
        'trend_change' => ':label (:change compared to the previous period)',
    ],

    'units' => [
        'per_minute' => '/min',
        'microseconds' => 'µs',
        'milliseconds' => 'ms',
        'seconds' => 's',
        'bytes' => 'B',
        'kilobytes' => 'KB',
        'megabytes' => 'MB',
    ],

    'empty' => [
        'no_data' => 'No response times were reported for these projects in the selected period.',
        'no_data_hint' => 'Response times come from the performance SDK of the monitored application. As long as nothing is set up there, this list stays empty even when error reports arrive.',
        'no_results' => 'No transaction matches this search.',
        'no_results_hint' => 'Search terms are combined with AND — the fewer of them, the more hits.',
    ],

    'truncated' => 'There are more than :limit transactions in this period. The :limit with the most traffic are shown; please refine the search.',

    'pagination' => [
        'summary' => 'Page :page of :pages · :total transactions',
        'previous' => 'Previous',
        'next' => 'Next',
    ],

    'transaction' => [
        'title' => 'Transaction',
        'back' => 'Back to the overview',

        'help' => [
            'purpose' => 'This page answers why a transaction is slow: how the response times are distributed, how they developed, which operation eats the time and which calls can be inspected.',
            'histogram' => 'The distribution shows whether all calls are equally slow or whether there are two groups. A second hill far to the right means there is a special path that takes much longer.',
            'sample' => 'Metrics and trend cover every measurement in the range. Time consumers, attributes and examples are computed from a limited sample of the most recent calls — its size is stated at each section.',
            'samples' => 'The examples are picked deliberately from percentile ranges and not at random: a random call would almost always be a fast one.',
            'facets' => 'A value is marked as an outlier once its p95 is at least one and a half times that of the other values — the "only this one release is slow" case.',
        ],

        'empty' => 'No response times were reported for this transaction in the selected range.',
        'empty_hint' => 'The range can be changed above. A link to "last 24 hours" shows different data tomorrow — that is the range, not an error.',

        'histogram' => [
            'title' => 'Distribution of response times',
            'bar' => ':count measurements between :from and :to',
            'open_end' => 'above',
            'hint' => 'Each class is twice as wide as the previous one: fast calls on the left, slow ones on the right.',
        ],

        'series' => [
            'title' => 'Trend (p95)',
            'point' => ':at · p95 :p95 from :count measurements',
            'deploy' => ':at · p95 :p95 from :count measurements · deployed: :version to :environment',
            'period_hour' => 'One bar per hour.',
            'period_day' => 'One bar per day.',
        ],

        'spans' => [
            'title' => 'Biggest time consumers',
            'description' => 'By operation, from :transactions calls. The shares refer to the total time of all steps — steps nest inside each other, so their sum exceeds the response time.',
            'detail' => ':count steps · :total in total · :average on average',
            'empty' => 'No individual steps were reported for these calls. Without them only the total duration is known — steps come from the SDK tracing.',
        ],

        'facets' => [
            'title' => 'Notable attributes',
            'description' => 'The p95 per release, environment and platform — from the same sample.',
            'empty' => 'No attribute has more than one value; there is nothing to compare.',
            'outlier' => 'notable',
            'keys' => [
                'release' => 'Release',
                'environment' => 'Environment',
                'platform' => 'Platform',
            ],
        ],

        'samples' => [
            'title' => 'Examples',
            'description' => 'One actual call per percentile range.',
            'empty' => 'No individual measurements are left for this transaction.',
            'detail' => ':at · :spans steps · :release',
            'no_release' => 'no release',
            'no_trace_view' => 'The trace view is not available yet.',
        ],

        'issues' => [
            'title' => 'Linked issues',
            'description' => 'Issues reported under this transaction name within the range.',
            'empty' => 'No issue was reported under this name within the range.',
        ],
    ],

];
