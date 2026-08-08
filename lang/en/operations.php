<?php

return [

    'title' => 'Operations',

    'help' => 'The state of this installation: what still answers, whether processing keeps up and what is stuck. The page asks anew on every visit — it caches nothing.',

    'health' => [
        'title' => 'Health',
        'description' => 'Checked by writing and reading back, not just by connecting. The same checks answer :route for load balancers and outside monitoring.',
        'checks' => [
            'database' => 'Database',
            'cache' => 'Cache',
            'queue' => 'Queue',
            'storage' => 'File storage',
        ],
        'overall_ok' => 'Everything answers',
        'overall_failed' => 'A component is not answering',
        'state' => [
            'ok' => 'ok',
            'failed' => 'failing',
        ],
    ],

    'backlog' => [
        'title' => 'Backlog',
        'description' => 'Accepted but not yet processed payloads. The count alone says little — what matters is how long the oldest one has been waiting.',
        'pending' => 'Waiting payloads',
        'oldest' => 'Oldest waiting for',
        'threshold' => 'Threshold: :pending payloads or :age seconds of age',
        'ok' => 'Within limits',
        'breaching' => 'Above the threshold since :since',
        'breaching_unknown' => 'Above the threshold',
        'reasons' => [
            'pending' => 'too many waiting payloads',
            'age' => 'oldest payload too old',
        ],
        'none' => 'nothing waiting',
        'seconds' => ':value s',
    ],

    'durations' => [
        'title' => 'Processing time',
        'description' => 'How long the pipeline computes per payload, across the last :count runs.',
        'empty' => 'No processed payload yet.',
        'avg' => 'Average',
        'p95' => '95th percentile',
        'max' => 'Longest',
    ],

    'latency' => [
        'title' => 'From acceptance to visibility',
        'description' => 'The duration a user notices — waiting in the queue included. If it drifts apart while processing time stays put, workers are missing.',
    ],

    'queues' => [
        'title' => 'Queues',
        'description' => 'Jobs waiting to be picked up. If all of them pile up at once, no worker is running.',
        'unknown' => 'not countable',
    ],

    'states' => [
        'title' => 'Payloads by state',
        'description' => 'Every payload ever accepted, by the outcome of its processing.',
    ],

    'failed_jobs' => [
        'title' => 'Failed jobs',
        'description' => 'Jobs that used up every attempt. Retrying runs them with the same payload as the first time.',
        'empty' => 'Nothing stuck.',
        'count' => ':count entries, showing the most recent :shown',
        'columns' => [
            'name' => 'Job',
            'queue' => 'Queue',
            'failed_at' => 'Failed at',
            'exception' => 'Reason',
        ],
        'retry' => 'Retry',
        'retry_all' => 'Retry all',
        'forget' => 'Discard',
        'retried_one' => 'The job has been queued again.',
        'retried_all' => ':count job(s) have been queued again.',
        'forgotten' => 'The entry has been discarded.',
        'gone' => 'That entry is gone — someone was faster.',
    ],

    'failed_payloads' => [
        'title' => 'Failed payloads',
        'description' => 'Not the same as failed jobs: here the raw data is still around. Once a step of the pipeline is fixed they can run again, even though the job is long gone.',
        'count' => ':count payload(s) are waiting for another run.',
        'empty' => 'No failed payload.',
        'retry' => 'Queue again',
        'retried' => ':count payload(s) have been queued again.',
        'limit_hint' => 'At most :limit at a time — more would bring back the very backlog you are working off.',
    ],

];
