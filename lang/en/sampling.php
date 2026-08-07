<?php

// Sampling of response times (resources/js/shell/pages/projects/Sampling.jsx,
// App\Http\Controllers\SamplingRuleController). The label of the "sampled"
// discard reason lives in enums.php, like every other enumeration.
return [

    'title' => 'Sampling · :project',
    'help' => 'Only a share of the reported response times is stored; the evaluations scale it back up. What is needed is not every single call but the distribution — and a sample carries that just as well. Error reports are never affected: they are always kept in full.',

    'rules' => [
        'title' => 'Sampling rules',
        'description' => 'The first rule whose conditions all match wins. If none matches, everything is kept.',
        'empty' => 'No rule yet — everything is kept. That is the right default: sampling should be a decision.',
        'inactive_badge' => 'disabled',
        'position' => 'Rank :position',
        'position_label' => 'Rank',
        'irreversible_hint' => 'Rules apply to future reports. Whatever a rule filters out is gone for good — unlike grouping, re-processing cannot bring it back.',
        'errors_hint' => 'Error reports are never affected by these rules, even where a condition would match. A crash is a single case, and a single case cannot be extrapolated.',
    ],

    'name' => 'Name',
    'name_hint' => 'What the rule is for. Six months on, that is the only explanation left.',

    'conditions' => [
        'label' => 'Conditions',
        'hint' => 'Every condition you fill in must match; empty ones are ignored by the rule. Patterns use * — not regular expressions. Case matters.',
        'all' => 'Without a condition: matches every call and is therefore the project default.',
        'transaction_name' => 'Transaction name',
        'environment' => 'Environment',
        'release' => 'Release',
        'op' => 'Request type',
        'placeholder' => [
            'transaction_name' => 'GET /health',
            'environment' => 'production',
            'release' => 'errstack@1.*',
            'op' => 'http.server',
        ],
    ],

    'rate' => [
        'label' => 'Keep',
        'hint' => 'The share that is stored — 1 % means one measurement in a hundred. Throughput is scaled back up in the evaluations; response times and failure rate stay correct as they are.',
        'suffix' => '%',
    ],

    'minimum' => [
        'label' => 'At least',
        'hint' => 'This many reports of one operation are always kept per window (:seconds s), even where the rate says otherwise. Without that floor, the nightly import that runs once an hour almost certainly disappears at a 1 % rate.',
        'suffix' => 'per window',
    ],

    'save' => 'Save',
    'disable' => 'Disable',
    'enable' => 'Enable again',
    'delete' => 'Delete',

    'create' => [
        'title' => 'Create rule',
        'description' => 'New rules go to the end. They only override existing ones once they are moved ahead of them.',
        'submit' => 'Create rule',
    ],

    'validation' => [
        'too_many' => 'More than :max rules per project is not possible. Every rule is checked against every reported transaction.',
    ],

    'flash' => [
        'created' => 'Rule “:name” created — it applies from the next report onwards.',
        'updated' => 'Rule “:name” saved.',
        'enabled' => 'Rule “:name” is active again.',
        'disabled' => 'Rule “:name” is disabled; everything it matched is kept again.',
        'deleted' => 'Rule “:name” deleted. Measurements already filtered out do not come back.',
    ],

];
