<?php

// Grouping of similar reports (resources/js/shell/pages/projects/Grouping.jsx,
// App\Http\Controllers\FingerprintRuleController). Labels for
// App\Enums\GroupingSource live in enums.php, like every enum.
return [

    'title' => 'Grouping · :project',
    'help' => 'Similar reports share a fingerprint and are folded into a single entry — otherwise ten thousand identical crashes would show up ten thousand times. The default method decides how; the rules below correct it wherever it groups too coarsely or too finely.',

    'rules' => [
        'title' => 'Fingerprint rules',
        'description' => 'The first rule whose conditions all match wins — even over a fingerprint sent by the SDK. With no matching rule the default method applies.',
        'empty' => 'No rules yet. The default method groups by exception type and stack trace, and messages by their template.',
        'inactive_badge' => 'disabled',
        'position' => 'Rank :position',
        'position_label' => 'Rank',
        'retroactive_hint' => 'Rules apply to future reports. Ones already processed keep their group — otherwise counters and timelines would stop adding up retroactively.',
    ],

    'name' => 'Name',
    'name_hint' => 'What the rule is for. Six months from now this is the only explanation left.',

    'matchers' => [
        'label' => 'Conditions',
        'hint' => 'All must match. Patterns use * and ? — not regular expressions. Without a wildcard the pattern matches exactly.',
        'attribute' => 'Field',
        'pattern' => 'Pattern',
        'negated' => 'does not match',
        'add' => 'Add condition',
        'remove' => 'Remove',
        'placeholder' => '*TimeoutException',
    ],

    'fingerprint' => [
        'label' => 'Fingerprint',
        'hint' => '{{ default }} inserts the components of the default method, which lets you refine instead of replace. Field placeholders such as {{ error.type }} or {{ tags.tenant }} work as well.',
        'add' => 'Add component',
        'remove' => 'Remove',
        'placeholder' => 'billing',
    ],

    'save' => 'Save',
    'disable' => 'Disable',
    'enable' => 'Enable',
    'delete' => 'Delete',

    'create' => [
        'title' => 'Add rule',
        'description' => 'New rules go last. They only override existing ones once you move them ahead.',
        'submit' => 'Add rule',
    ],

    'validation' => [
        'attribute' => 'No such field. Allowed are the listed fields plus tags.<name>.',
        'only_default' => 'A rule that sets nothing but {{ default }} does what the default method already does — while looking as if it did something.',
        'too_many' => 'A project cannot have more than :max rules. Every rule is checked against every report.',
    ],

    'flash' => [
        'created' => 'Rule “:name” added — it applies from the next report on.',
        'updated' => 'Rule “:name” saved.',
        'enabled' => 'Rule “:name” is active again.',
        'disabled' => 'Rule “:name” is disabled and no longer applies.',
        'deleted' => 'Rule “:name” deleted. Reports already grouped stay where they are.',
    ],

];
