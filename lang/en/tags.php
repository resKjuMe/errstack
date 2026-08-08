<?php

// The tag breakdown (app/Http/Controllers/TagController,
// app/Http/Controllers/IssueTagController, resources/js/shell/pages/tags,
// resources/js/shell/pages/issues/Tags.jsx).
return [

    'title' => 'Tags',
    'help' => 'What an issue happens with: browser, operating system, release, '
        .'server and the tags the application sets itself. Every value shows how '
        .'many reports carried it and what share that is — an issue that only '
        .'happens in one browser is a different case from one that hits everyone. '
        .'Clicking a value opens the issue list narrowed down to it.',

    'issue' => [
        'title' => 'Tags of this issue',
        'back' => 'Back to the issue list',
        'times_seen' => ':count reports in total',
    ],

    'project' => [
        'title' => 'Tags of the selection',
        'intro' => 'Every tag of the selected projects, most frequent first.',
    ],

    'detail' => [
        'back' => 'Back to the overview',
        'values' => ':count distinct values',
        'total' => ':count reports carrying this tag',
        'capped' => 'At most :limit distinct values are kept for this tag. The rest '
            .'still counts towards the total, but does not appear as its own row.',
    ],

    'list' => [
        'all_values' => 'All values',
        'rest' => 'Other',
        'filter' => 'Narrow the issue list down to this value',
        'empty' => 'No tags recorded yet.',
        'empty_hint' => 'Tags are recorded as reports come in. As soon as the first '
            .'one arrives, it shows up here.',
    ],

    'period_ignored' => 'The selected period does not narrow this breakdown: tags '
        .'are counted across the entire lifetime of an issue.',

    'filter' => [
        'active' => 'Narrowed down to :key: :value',
        'clear' => 'Clear this filter',
    ],

    'link' => [
        'issue' => 'Tags',
        'overview' => 'Tags of the selection',
    ],

    // Written-out names of the tags that come from the fixed fields of a report
    // (App\Support\Tags\EventTags). Tags set by the application keep their own
    // name.
    'keys' => [
        'level' => 'Level',
        'platform' => 'Platform',
        'environment' => 'Environment',
        'release' => 'Release',
        'dist' => 'Distribution',
        'server_name' => 'Server',
        'transaction' => 'Transaction',
        'logger' => 'Logger',
        'url' => 'URL',
        'browser' => 'Browser',
        'browser_name' => 'Browser (without version)',
        'os' => 'Operating system',
        'os_name' => 'Operating system (without version)',
        'device' => 'Device',
        'device_family' => 'Device family',
        'runtime' => 'Runtime',
        'runtime_name' => 'Runtime (without version)',
        'sdk' => 'SDK',
    ],

];
