<?php

// Uptime monitoring (resources/js/shell/pages/projects/Uptime.jsx,
// App\Http\Controllers\UptimeMonitorController and the alert texts in
// App\Support\Uptime\UptimeAlerts).
return [

    'title' => 'Uptime · :project',
    'help' => 'A monitored target is called from the outside at a fixed interval. If it does not answer as expected, the request is repeated for confirmation — only then does it count as an outage, a notification goes out, and the incident also shows up as an issue. Checks run in the background only; this page never calls anything itself.',

    'name' => 'Name',
    'name_hint' => 'For display only — it appears in notifications and in the title of the issue.',

    'url' => 'URL',
    'url_hint' => 'Complete, with http:// or https://. Best pick an address that really goes through the application, not just the web server.',

    'method' => 'Method',
    'method_hint' => 'GET covers almost everything. HEAD transfers no body and therefore rules out the content check.',

    'headers' => 'Headers',
    'headers_hint' => 'For targets that expect something — a token, a language, a header of their own. Empty rows are discarded.',
    'header_name' => 'Name',
    'header_value' => 'Value',
    'header_add' => 'Add header',
    'header_remove' => 'Remove',

    'body' => 'Payload',
    'body_hint' => 'Only for POST, PUT and PATCH. Without an explicit Content-Type header it is sent as JSON.',

    'expected_status_codes' => 'Expected status codes',
    'expected_status_codes_hint' => 'Ranges and single values, separated by commas: “200-299” or “200-299,301”. Anything else counts as an outage.',

    'expected_content' => 'Expected text',
    'expected_content_hint' => 'If this text does not appear in the body, the target counts as down. The difference between “the web server answers” and “the application runs” — an error page with status 200 is the outage a plain status check misses.',

    'interval' => 'Interval (seconds)',
    'interval_hint' => 'At least 60 seconds — the application scheduler cannot trigger any finer.',
    'timeout' => 'Timeout (seconds)',
    'timeout_hint' => 'How long to wait for an answer. After that the request counts as a timeout.',

    'confirmation_retries' => 'Confirmation attempts',
    'confirmation_retries_hint' => 'How often a failed request is repeated right away before it counts. 0 means no confirmation — then every hiccup reports an outage.',
    'confirmation_delay' => 'Wait before confirming (seconds)',
    'confirmation_delay_hint' => 'Asking again immediately hits the same half-open connection. A few seconds of distance are half the point.',

    'failure_threshold' => 'Outage after … failures',
    'failure_threshold_hint' => 'An outage begins only after this many failed checks in a row. 1 means right after the confirmed check.',
    'recovery_threshold' => 'Recovery after … successes',
    'recovery_threshold_hint' => 'This many successful checks in a row before the incident is closed. Raise it for targets that flap.',

    'follow_redirects' => 'Follow redirects',
    'verify_tls' => 'Verify certificate',
    'verify_tls_hint' => 'An expired certificate is an outage. Turn this off only for internal targets with their own certificate authority.',

    'active' => 'Monitoring active',
    'save' => 'Save',
    'disable' => 'Disable monitoring',
    'enable' => 'Enable monitoring',
    'delete' => 'Delete',
    'disable_hint' => 'Disabled, everything stays in place, it is just no longer checked — the way to park a target for planned maintenance.',

    'settings' => 'Settings',

    'facts' => [
        'url' => 'Target',
        'interval' => 'Interval',
        'interval_value' => 'every :seconds s',
        'last_checked' => 'Last checked',
        'next_check' => 'Next check',
        'never' => 'never',
        'average' => 'Response time (24 h)',
        'failures' => 'Failures in a row',
        'failures_value' => ':count of :threshold',
    ],

    'availability' => [
        'title' => 'Availability',
        'day' => '24 hours',
        'week' => '7 days',
        'month' => '30 days',
        'none' => 'no measurement',
        'checks' => ':count checks, :failures of them failed',
    ],

    'response_times' => [
        'title' => 'Response times',
        'empty' => 'No measurement yet.',
        'summary' => ':count measurements, :from to :to',
    ],

    'outages' => [
        'title' => 'Outages (:count)',
        'empty' => 'No outage recorded.',
        'reason' => 'Reason',
        'started' => 'Start',
        'ended' => 'End',
        'duration' => 'Duration',
        'running' => 'still running',
        'issue' => 'Issue',
        'open_issue' => 'open',
        'checks' => ':count failed checks',
    ],

    'empty' => [
        'title' => 'No monitored target yet',
        'description' => 'Enter an address that should be reachable from the outside. A total outage produces no error report — nothing is left running that could send one.',
    ],

    'create' => [
        'title' => 'Monitor a target',
        'description' => 'Interval, expectation and confirmation decide when an outage is noticed and when it is reported.',
        'submit' => 'Create monitor',
    ],

    'validation' => [
        'status_codes' => 'Those are not valid status codes. Allowed are three-digit numbers and ranges, separated by commas — for example “200-299,301”.',
        'timeout_fits_interval' => 'Timeout and confirmation add up to :seconds seconds and therefore do not fit into the interval. Shorten them or widen the interval.',
        'content_needs_body' => 'A HEAD transfers no body — a content check would fail on every run.',
    ],

    'flash' => [
        'created' => 'Target “:name” is now monitored.',
        'updated' => 'Target “:name” saved.',
        'enabled' => 'Monitoring of “:name” is active again.',
        'disabled' => 'Monitoring of “:name” is disabled.',
        'deleted' => 'Monitoring of “:name” deleted.',
    ],

    // Error texts from the check itself (App\Support\Uptime\UptimeProbe). They
    // appear in the history, on the outage and in the notification.
    'probe' => [
        'status_mismatch' => 'The target answered with HTTP :status; expected was :expected.',
        'content_mismatch' => 'The expected text “:text” did not appear in the response.',
    ],

    // Title of the issue an outage creates (App\Support\Uptime\UptimeIssues).
    'issue' => [
        'title' => 'Unreachable: :monitor',
    ],

    // Alert and recovery texts (App\Support\Uptime\UptimeAlerts). They go out
    // over the organization's channels and are read outside the interface too —
    // which is why they name project and target explicitly.
    'alert' => [
        'title' => 'Unreachable: :monitor',
        'body' => 'The target “:monitor” in project “:project” is unreachable (:reason). Checked was :url.',
        'context_project' => 'Project',
        'context_url' => 'URL',
        'context_reason' => 'Reason',
        'context_started' => 'Start',
        'context_status' => 'Status code',
        'context_error' => 'Message',
        'context_duration' => 'Duration',
    ],

    'recovery' => [
        'title' => 'Reachable again: :monitor',
        'body' => 'The target “:monitor” in project “:project” answers again. The outage lasted :duration.',
    ],

];
