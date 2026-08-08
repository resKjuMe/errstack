<?php

// Latency trends (app/Http/Controllers/PerformanceTrendController,
// app/Support/Performance/Trends, resources/js/shell/pages/performance/Trends).
return [

    'title' => 'Latency trends',
    'help' => 'Transactions whose response time has shifted — not for a moment, but '
        .'for good. The detection looks for the point in time where the distribution '
        .'moved and compares the percentiles before and after it with a rank-sum test. '
        .'A single spike is not enough, and without sufficient measurements nothing is '
        .'reported at all.',

    'list' => [
        'empty' => 'No trend breaks in the selected period.',
        'empty_hint' => 'The scan runs hourly over the response times of the past week. '
            .'Only what holds up statistically is reported.',
        'count' => ':count trend breaks',
        'from_to' => 'from :before to :after',
        'samples' => ':before measurements before, :after after',
        'confidence' => 'Confidence :value σ',
        'breakpoint' => 'Break: :value',
        'deploy' => 'Deploy :version (:at)',
        'no_deploy' => 'No deploy close in time',
        'seen_by' => 'Seen by :name, :at',
        'seen_at' => 'Seen: :at',
    ],

    'columns' => [
        'transaction' => 'Transaction',
        'change' => 'Change',
        'breakpoint' => 'Break',
        'cause' => 'Likely cause',
        'state' => 'State',
    ],

    'actions' => [
        'mark_seen' => 'Mark as seen',
        'mark_unseen' => 'Mark as unseen',
    ],

    // The values of App\Support\Performance\Trends\TrendListSort.
    'sort' => [
        'impact' => 'Largest change',
        'recent' => 'Most recent break',
    ],

    'filter' => [
        'sort' => 'Sorting',
        'direction' => 'Direction',
        'any_direction' => 'Any direction',
        'worse' => 'Regressions',
        'better' => 'Improvements',
        'seen' => 'State',
        'open' => 'Open',
        'done' => 'Seen',
        'any_seen' => 'All',
    ],

    'thresholds' => 'A break is reported from :change change on, with at least :samples '
        .'measurements and :windows hours on each side and a confidence of :confidence σ.',

    'overview' => 'Back to overview',
    'link' => 'Trend breaks',

    'flash' => [
        'seen' => '“:transaction” is marked as seen.',
        'unseen' => '“:transaction” is open again.',
    ],

    'notification' => [
        'title' => ':transaction got slower (:project)',
        'body' => 'The response time of “:transaction” has been persistently higher '
            .'since :at: the 95th percentile is at :after instead of :before, which is '
            .':change above the previous level.',
        'samples' => ':before before, :after after',
        'deploy' => ':version, deployed :at',
        'context_project' => 'Project',
        'context_environment' => 'Environment',
        'context_transaction' => 'Transaction',
        'context_before' => 'Before (p95)',
        'context_after' => 'After (p95)',
        'context_samples' => 'Measurements',
        'context_deploy' => 'Likely cause',
    ],

];
