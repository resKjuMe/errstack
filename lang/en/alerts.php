<?php

// Threshold alerts on metrics (app/Http/Controllers/MetricAlertController,
// app/Support/Alerts, resources/js/shell/pages/projects/Alerts.jsx).
return [

    'title' => 'Alerts — :project',
    'help' => 'An alert regularly looks at a metric and speaks up when it breaches a '
        .'threshold — and once more when it is back to normal. It is measured over a '
        .'time window that moves on every minute. Only a change of state is reported, '
        .'not every evaluation: an ongoing problem yields one message, not sixty an hour.',

    'intro' => [
        'title' => 'How an alert works',
        'description' => 'The warning and critical thresholds decide when the alert fires. '
            .'The resolve threshold decides when it clears — it deliberately sits beyond the '
            .'firing ones, so a value hovering around the line does not alternate between '
            .'alert and all-clear.',
        'gaps_hint' => 'When no measurements arrive within the window, the state stays as it '
            .'is: no response time and no failure rate follow from zero measurements. Counts '
            .'are different — there "nothing" really is zero, and the alert clears.',
    ],

    'list' => [
        'empty' => 'No alert set up yet.',
        'inactive_badge' => 'switched off',
        'subtitle' => ':metric over :minutes minutes',
        'last_value' => 'Last measured',
        'no_value' => 'no value',
        'last_evaluated' => 'Last evaluated',
        'never_evaluated' => 'never',
        'status_since' => 'State since',
    ],

    'create' => [
        'title' => 'Create an alert',
        'description' => 'At least one of the two firing thresholds is required — an alert '
            .'without a threshold evaluates every minute and never says anything.',
    ],

    'fields' => [
        'name' => 'Name',
        'metric' => 'Metric',
        'direction' => 'Direction',
        'comparison' => 'Comparison',
        'window' => 'Time window (minutes, :min–:max)',
        'environment' => 'Environment',
        'all_environments' => 'All environments',
        'transaction' => 'Transaction',
        'all_transactions' => 'All transactions',
        'warning' => 'Warning threshold',
        'critical' => 'Critical threshold',
        'resolve' => 'Resolve threshold',
        'minimum_samples' => 'Minimum measurements',
        'percent_change_hint' => 'The thresholds are read as a change in percent against the '
            .'same window one week ago.',
    ],

    'thresholds' => [
        'warning' => 'Warning',
        'critical' => 'Critical',
        'resolve' => 'Resolve',
    ],

    'actions' => [
        'create' => 'Create alert',
        'save' => 'Save',
        'preview' => 'Show preview',
        'previewing' => 'Calculating …',
        'enable' => 'Switch on',
        'disable' => 'Switch off',
        'delete' => 'Delete',
    ],

    'preview' => [
        'caption' => 'The last :windows windows of :minutes minutes, with the thresholds on top.',
        'label' => 'Metric over time, window of :minutes minutes',
        'empty' => 'No values for the period shown.',
    ],

    'history' => [
        'title' => 'History',
        'description' => 'The most recent state changes across all alerts of this project.',
        'empty' => 'No state change yet.',
    ],

    // The kind of transition (App\Models\MetricAlertTransition::kind()).
    'kind' => [
        'fired' => 'Fired',
        'escalated' => 'Escalated',
        'eased' => 'Eased',
        'resolved' => 'Resolved',
    ],

    'notification' => [
        'fired_title' => 'Alert: :alert',
        'fired_body' => 'The alert ":alert" in project :project has fired. :metric is at '
            .':value over the last :minutes minutes; the threshold is :threshold.',

        'escalated_title' => 'Alert escalated: :alert',
        'escalated_body' => 'The alert ":alert" in project :project has escalated. :metric is '
            .'at :value over the last :minutes minutes; the threshold is :threshold.',

        'eased_title' => 'Alert eased: :alert',
        'eased_body' => 'The alert ":alert" in project :project has eased but is not resolved '
            .'yet. :metric is at :value over the last :minutes minutes.',

        'resolved_title' => 'All clear: :alert',
        'resolved_body' => 'The alert ":alert" in project :project is resolved. :metric is '
            .'back at :value over the last :minutes minutes.',

        'no_threshold' => 'none',
        'minutes' => ':minutes minutes',
        'context_project' => 'Project',
        'context_metric' => 'Metric',
        'context_window' => 'Time window',
        'context_value' => 'Value',
        'context_status' => 'State',
        'context_environment' => 'Environment',
        'context_transaction' => 'Transaction',
        'context_baseline' => 'Previous week',
    ],

    'flash' => [
        'created' => 'Alert ":name" created.',
        'updated' => 'Alert ":name" saved.',
        'enabled' => 'Alert ":name" switched on.',
        'disabled' => 'Alert ":name" switched off.',
        'deleted' => 'Alert ":name" deleted.',
    ],

    'validation' => [
        'threshold_required' => 'At least one of the two thresholds must be set.',
        'critical_above' => 'The critical threshold must be above the warning threshold.',
        'critical_below' => 'The critical threshold must be below the warning threshold.',
        'resolve_below' => 'The resolve threshold must be below the firing threshold.',
        'resolve_above' => 'The resolve threshold must be above the firing threshold.',
        'transaction_not_supported' => 'This metric has no transaction — an error report does '
            .'not carry a transaction name.',
        'too_many' => 'A project can have at most :max alerts.',
    ],

];
