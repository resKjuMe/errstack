<?php

// Monitored cron jobs (resources/js/shell/pages/projects/Crons.jsx,
// App\Http\Controllers\CronMonitorController and the alert texts in
// App\Support\Crons\CronAlerts).
return [

    'title' => 'Cron jobs · :project',
    'help' => 'A monitored job checks in on every run. If the check-in fails to arrive, the job runs too long or reports a failure, a message goes out automatically — through the same channels as any other alert. The identifier below belongs in the job; it cannot be changed later, so that the check-ins keep arriving.',

    'name' => 'Name',
    'name_hint' => 'For display only. The identifier used by the job is derived from it when the monitor is created and stays fixed afterwards.',

    'schedule_type' => 'Kind of schedule',
    'expression' => 'Cron expression',
    'expression_hint' => 'Five fields as in a crontab: minute, hour, day, month, weekday. "0 2 * * *" means daily at 02:00.',
    'interval_value' => 'Every',
    'interval_unit' => 'Unit',

    'timezone' => 'Time zone',
    'timezone_hint' => 'The time zone the job runs in — not the viewer\'s. Without it, "daily at 02:00" says nothing.',

    'margin' => 'Grace period (minutes)',
    'margin_hint' => 'For this long after the scheduled time the job still counts as on time. After that the run counts as missed.',
    'max_runtime' => 'Maximum run time (minutes)',
    'max_runtime_hint' => 'If the job reports no end after this long, the run counts as stuck.',
    'failure_tolerance' => 'Alert after … failures',
    'failure_tolerance_hint' => 'Only after this many consecutive failures does a message go out. 1 means immediately.',
    'recovery_tolerance' => 'All clear after … successes',
    'recovery_tolerance_hint' => 'This many consecutive successful runs before the all clear is given.',

    'active' => 'Monitoring active',
    'save' => 'Save',
    'disable' => 'Turn monitoring off',
    'enable' => 'Turn monitoring on',
    'delete' => 'Delete',
    'disable_hint' => 'Turned off, everything stays in place — nothing is detected any more. The way to handle planned maintenance.',

    'copy' => 'Copy',
    'copied' => 'Copied',
    'check_in_url_hint' => 'Call this address at the end of the job — that is enough as a check-in. With "?status=in_progress" at the start and "?status=error" on failure, the run time is recorded as well.',

    'schedule' => [
        'every' => 'every :value :unit',
        'invalid' => 'The schedule cannot be read — please correct it.',
    ],

    'facts' => [
        'slug' => 'Identifier',
        'last_check_in' => 'Last check-in',
        'next_due' => 'Next run',
        'never' => 'never',
        'failures' => 'Consecutive failures',
        'failures_value' => ':count of :tolerance',
    ],

    'history' => [
        'title' => 'History (:count)',
        'empty' => 'No run recorded yet.',
        'status' => 'Result',
        'expected' => 'Scheduled',
        'started' => 'Started',
        'duration' => 'Duration',
        'environment' => 'Environment',
    ],

    'empty' => [
        'title' => 'No monitored cron job yet',
        'description' => 'Create one — or send a check-in carrying "monitor_config": then the monitor appears on the first run by itself.',
    ],

    'create' => [
        'title' => 'Monitor a cron job',
        'description' => 'Schedule and grace period decide when a missing run becomes visible.',
        'submit' => 'Create monitor',
    ],

    'validation' => [
        'expression' => 'That is not a valid cron expression.',
    ],

    'flash' => [
        'created' => 'Cron job ":name" is being monitored — identifier for the check-in: :slug',
        'updated' => 'Cron job ":name" saved.',
        'enabled' => 'Monitoring of ":name" is active again.',
        'disabled' => 'Monitoring of ":name" is turned off.',
        'deleted' => 'Monitoring of ":name" deleted.',
    ],

    // Alert and all-clear texts (App\Support\Crons\CronAlerts). They go out
    // through the organisation's channels and are read outside the interface
    // too — which is why they name the project and the job.
    'alert' => [
        'title' => 'Cron job ":monitor": :reason',
        'body_missed' => 'The job ":monitor" in project ":project" did not check in. The run was expected at :expected.',
        'body_timeout' => 'The job ":monitor" in project ":project" has been running for more than :runtime minutes and reported no end. The run was expected at :expected.',
        'body_error' => 'The job ":monitor" in project ":project" reported itself as failed. The run was expected at :expected.',
        'unknown_time' => 'unknown',
        'unknown_schedule' => 'unreadable',
        'context_project' => 'Project',
        'context_monitor' => 'Identifier',
        'context_schedule' => 'Schedule',
        'context_environment' => 'Environment',
        'context_duration' => 'Duration',
    ],

    'recovery' => [
        'title' => 'Cron job ":monitor" is running again',
        'body' => 'The job ":monitor" in project ":project" completed successfully again.',
    ],

];
