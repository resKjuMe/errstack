<?php

// Notification digests (resources/js/shell/pages/projects/Digest.jsx,
// App\Http\Controllers\ProjectDigestController, resources/views/mail/digest.blade.php).
return [

    'title' => 'Digest · :project',
    'help' => 'Several notifications within one time window are combined into a single message. This is meant for the error wave: nobody reads twenty mails about the same problem, but they do read one digest with twenty entries.',

    'intro' => [
        'title' => 'How bundling works',
        'description' => 'It only affects the personal mails to members. The organization channels (Slack, webhook) keep receiving their notifications individually.',
        'critical_hint' => 'Urgent notifications are never bundled — a crash ("fatal") goes out immediately and on its own, even in the middle of the window.',
        'personal_hint' => 'Every member may turn bundling off for themselves and then receives individual notifications again:',
        'personal_link' => 'personal notifications',
        'waiting' => ':count notifications are currently waiting for their digest.',
    ],

    'settings' => [
        'title' => 'Window and limits',
        'description' => 'A window of 0 minutes turns bundling off — every notification then goes out immediately and on its own.',
        'read_only_description' => 'These values are changed by the organization’s administrators.',
        'window' => 'Time window (minutes)',
        'window_hint' => 'Counted from the first waiting notification. 0 turns bundling off.',
        'window_off' => 'off',
        'window_value' => ':minutes minutes',
        'min' => 'Minimum count',
        'min_hint' => 'If fewer notifications come together, they go out individually instead of as a digest.',
        'max' => 'Maximum count',
        'max_hint' => 'Once reached, the digest goes out right away without waiting for the window.',
        'submit' => 'Save',
    ],

    'flash' => [
        'settings_saved' => 'The digest settings have been saved.',
    ],

    'mail' => [
        'subject' => ':count notifications from :project',
        'heading' => ':count notifications from :project',
        'intro' => 'These notifications came together within the digest window.',
        'open_item' => 'View',
        'origin' => 'Digest from :project · Level: :level · Event: :event',
        'settings_link' => 'Notification settings',
    ],

];
