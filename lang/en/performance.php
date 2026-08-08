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

];
