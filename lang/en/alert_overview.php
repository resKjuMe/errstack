<?php

// Alert overview and history (app/Http/Controllers/AlertOverviewController,
// app/Http/Controllers/AlertSnoozeController, app/Support/Alerts,
// resources/js/shell/pages/projects/AlertOverview.jsx and AlertDetail.jsx).
return [

    'title' => 'Alert overview — :project',
    'detail_title' => ':alert — :project',
    'help' => 'Every alert of this project in one place: metric alerts on measurements and '
        .'alert rules for errors, with state, last trigger and history. They are still set '
        .'up on their own pages — this one is for looking things up and, if need be, for '
        .'buying some quiet.',

    'intro' => [
        'title' => 'What this page answers',
        'description' => 'After a notification the question is rarely „which metric?" but '
            .'„which rule was that, and how often does it happen?". That is why both kinds '
            .'share one list, sorted by their last trigger.',
        'snooze_hint' => 'Snoozing suppresses the notification, not the evaluation: state '
            .'changes and triggers still show up in the history. Buying a quiet night does '
            .'not cost you the record of what happened during it.',
    ],

    'kinds' => [
        'metric' => 'Metric alert',
        'issue' => 'Error rule',
    ],

    'states' => [
        'all' => 'All states',
        'fired' => 'Triggered',
        'warning' => 'Warning',
        'critical' => 'Critical',
        'resolved' => 'Resolved',
        'armed' => 'Armed',
        'off' => 'Disabled',
    ],

    'scopes' => [
        'everyone' => 'For everyone',
        'personal' => 'Only for me',
    ],

    'durations' => [
        60 => '1 hour',
        120 => '2 hours',
        240 => '4 hours',
        480 => '8 hours',
        1440 => '24 hours',
        4320 => '3 days',
        10080 => '7 days',
    ],

    'filter' => [
        'period' => 'Period',
        'state' => 'State',
        'range' => 'History from :from to :to',
    ],

    'list' => [
        'title' => 'Rules and alerts',
        'empty' => 'No alert and no alert rule has been set up for this project yet.',
        'frequency' => 'At most one notification per error every :minutes minutes',
        'last' => 'Last triggered',
        'never' => 'never',
        'count' => 'In this period',
        'count_value' => ':count ×',
        'state_since' => 'State since',
        'detail' => 'View history',
        'config' => 'Settings',
    ],

    'snooze' => [
        'title' => 'Snooze',
        'duration' => 'Duration',
        'scope' => 'Scope',
        'submit' => 'Snooze',
        'lift' => 'Lift snooze',
        'everyone_active' => 'Snoozed for everyone until :until',
        'everyone_active_by' => 'Snoozed for everyone until :until — set by :by',
        'mine_active' => 'Snoozed for me until :until',
        'no_personal_effect' => 'This rule only notifies shared channels. Snoozing it just for '
            .'me would have no effect there — it only falls silent for everyone.',
        'manage_only' => 'Only admins may snooze for everyone.',
    ],

    'history' => [
        'title' => 'History',
        'empty' => 'Nothing happened in the selected period.',
        'deliveries' => ':count deliveries',
        'no_deliveries' => 'no delivery',
        'truncated' => 'Showing the most recent :limit entries.',
    ],

    'chart' => [
        'title' => 'Triggers per interval',
        'label' => 'Triggers in the selected period',
        'total' => ':count entries in this period',
        'truncated' => 'Counted the most recent :limit entries — there were more.',
        'empty' => 'Nothing to show for the selected period.',
    ],

    'detail' => [
        'back' => 'Back to the overview',
        'facts' => 'Configuration',
        'actions' => 'Triggered actions',
        'metric_chart' => 'Metric history',
        'deliveries' => 'Deliveries',
        'deliveries_empty' => 'Nothing has gone out to a channel for this rule yet.',
        'deliveries_hint' => 'Listed are the deliveries to the shared channels. Personal '
            .'notifications go out as mail to the individual and are not logged; deliveries '
            .'from before this view was introduced are missing.',
        'delivery_attempts' => ':count attempts',
        'delivered_at' => 'Delivered :at',
    ],

    'facts' => [
        'window' => 'Time window',
        'conditions' => 'Triggers',
        'filters' => 'Filters',
        'frequency' => 'Rate limit',
        'none' => 'none',
    ],

    'actions' => [
        'all_channels' => 'To every active channel of the organisation',
    ],

    'flash' => [
        'snoozed' => '„:name" is snoozed until :until.',
        'unsnoozed' => 'The snooze on „:name" has been lifted.',
    ],

];
