<?php

// Privacy pages (resources/js/shell/pages/privacy) and the messages of the
// corresponding controllers.
return [

    'title' => 'Privacy — :name',
    'help' => 'What is configured here takes effect as a report arrives — before anything is stored. Removed values are replaced by a marker so it stays visible that something was there.',

    'scope' => [
        'organization' => 'These rules apply to every project of the organization.',
        'project' => 'These rules apply to this project only. The rules of the organization apply on top.',
    ],

    'options' => [
        'title' => 'What is never stored',
        'description' => 'Turn off whole kinds of information. Takes effect with the next incoming report; events already stored are left untouched.',
        'read_only' => 'The administration of the organization can change them.',
        'ip' => 'Do not store IP addresses',
        'ip_hint' => 'Removes the address everywhere it appears: on the affected person, in the server environment and in the headers of a proxy. Counting how many different people an error hit is no longer possible.',
        'user' => 'Do not store user data',
        'user_hint' => 'Removes the whole section about the affected person — including whatever the SDK adds on top.',
        'attachments' => 'Do not store attachments',
        'attachments_hint' => 'Discards screenshots and log files on arrival. There is nothing to redact in a file: it is either harmless or not at all. Session replays are not covered — they have their own retention below.',
        'replay_retention' => 'Keep replays (days)',
        'replay_retention_hint' => 'Session replays are stored separately from event data and deleted after this period — independent of how long errors are kept. Empty means the operator default (:default days). Zero means no recording at all.',
        'replay_retention_placeholder' => 'Default (:default)',
        'replay_retention_off' => 'At 0 nothing is recorded: incoming segments are discarded rather than stored and swept later.',
        'submit' => 'Save',
    ],

    'rules' => [
        'title' => 'Own rules',
        'description' => 'For everything only your own application knows — a customer number, an internal identifier, a field holding a staff number.',
        'empty' => 'No own rules yet. The default fields still apply.',
        'type' => 'Kind',
        'expression' => 'Field name or pattern',
        'expression_hint_field' => 'Case does not matter. "*" stands for any number of characters: "customer_*" matches "customer_id" and "customer_name".',
        'expression_hint_pattern' => 'A regular expression searched for in the value. The match is replaced, not the whole field.',
        'path' => 'Only in this section',
        'path_hint' => 'A path inside the report, e.g. "request.data" or "extra". Empty means: in the whole report.',
        'path_placeholder' => 'whole report',
        'active' => 'Active',
        'inactive' => 'turned off',
        'add' => 'Add rule',
        'save' => 'Save',
        'delete' => 'Delete',
    ],

    'inherited' => [
        'title' => 'Rules of the organization',
        'description' => 'They apply here as well and are maintained at the organization.',
        'manage' => 'Change at the organization',
    ],

    'defaults' => [
        'title' => 'Always removed',
        'description' => 'These fields and patterns disappear without any configuration — even for a freshly created project.',
        'marker' => 'What stands in their place afterwards is ":marker".',
        'fields' => 'Field names',
        'patterns' => 'Patterns in the value',
        'show' => 'Show default rules (:count)',
    ],

    'preview' => [
        'title' => 'Preview',
        'description' => 'Shows on a sample event what the active rules would remove. The sample can be edited — no real report is read for this.',
        'sample' => 'Sample event (JSON)',
        'submit' => 'Compute preview',
        'result' => 'Result',
        'removed' => 'Removed (:count)',
        'removed_none' => 'No rule matched.',
        'truncated' => 'Further matches are not listed individually.',
    ],

    'validation' => [
        'expression' => 'The expression cannot be evaluated. For a pattern it has to be a valid regular expression.',
        'path' => 'The section may only contain letters, digits, dots, hyphens and underscores.',
        'sample' => 'The sample has to be a JSON object.',
    ],

    'flash' => [
        'options_updated' => 'Privacy settings saved.',
        'rule_created' => 'Rule created.',
        'rule_updated' => 'Rule saved.',
        'rule_deleted' => 'Rule deleted.',
    ],

];
