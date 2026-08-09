<?php

return [
    'title' => 'Spike protection — :project',
    'help' => 'Detects unusual floods of events from the project\'s own history, throttles ingestion and tells the team. Whatever is dropped is listed on this page.',

    'intro' => [
        'title' => 'What this protects against',
        'description' => 'A broken release can produce more events in minutes than the project normally sees in weeks. Spike protection recognises that from this project\'s history and caps ingestion, so storage and quota are not used up overnight.',
        'counted_hint' => 'Throttled events are never dropped silently: they are counted, and the number is shown below.',
        'baseline_hint' => 'The comparison is against the last :minutes minutes of this project, not a fixed number — ten thousand events per minute are business as usual for one application and an incident for another.',
    ],

    'detection' => [
        'title' => 'What it currently measures against',
        'description' => 'The baseline comes from the history so far; throttled minutes are left out of it.',
        'baseline' => 'Baseline',
        'baseline_value' => ':value events/minute',
        'threshold' => 'Threshold',
        'threshold_value' => 'from :value events/minute',
        'threshold_off' => 'none yet',
        'samples' => 'Minutes measured',
        'samples_value' => ':samples of :required',
        'not_ready' => 'Only :samples of :required measured minutes are available. Until then the protection deliberately does not decide — a baseline from a handful of minutes says nothing about normal operation.',
        'disabled' => 'Spike protection is switched off for this project. Nothing is throttled and no history is recorded.',
    ],

    'current' => [
        'title' => 'Currently throttling',
        'description' => 'Since :since. New events are counted and dropped until the volume settles or somebody lifts the throttle.',
        'discarded' => 'Dropped so far',
        'peak' => 'Busiest minute',
        'threshold' => 'Threshold when it triggered',
        'release' => 'Lift the throttle',
        'release_hint' => 'Events are then accepted in full again. To avoid throttling immediately once more, a quiet period of :minutes minutes applies afterwards.',
        'release_hint_none' => 'Events are then accepted in full again. Without a quiet period the protection may trigger again in the very next minute.',
    ],

    'idle' => [
        'title' => 'Not throttling',
        'description' => 'Ingestion is running in full. :count events have been dropped by the protection so far.',
    ],

    'chart' => [
        'title' => 'The last hour',
        'description' => 'Events received per minute. Throttled minutes are highlighted; the last minute is still running.',
        'empty' => 'No history has been recorded for this project yet.',
        'minute' => ':minute',
        'events' => ':count events',
        'throttled' => 'throttled',
    ],

    'history' => [
        'title' => 'Earlier triggers',
        'description' => 'The most recent throttles for this project and how much each of them dropped.',
        'empty' => 'The protection has never triggered for this project.',
        'started' => 'Start',
        'ended' => 'End',
        'peak' => 'Busiest minute',
        'discarded' => 'Dropped',
        'released_by' => 'lifted by :name',
        'ended_on_its_own' => 'ended on its own',
    ],

    'settings' => [
        'title' => 'Settings',
        'description' => 'Whether the protection applies and when a minute counts as a spike.',
        'read_only_description' => 'Only the organisation\'s administrators may change this.',
        'enabled' => 'Enable spike protection',
        'enabled_hint' => 'While it is off, nothing is throttled and no history is recorded.',
        'factor' => 'Factor',
        'factor_hint' => 'How many times the baseline a minute has to reach to count as a spike.',
        'minimum' => 'Floor',
        'minimum_hint' => 'Below this many events per minute nothing is ever throttled — otherwise a single short burst is enough for a quiet project.',
        'release_minutes' => 'Quiet period',
        'release_minutes_hint' => 'How long after lifting a throttle by hand no new one may start. 0 means no quiet period.',
        'submit' => 'Save',
        'on' => 'on',
        'off' => 'off',
        'factor_value' => ':value×',
        'minutes_value' => ':value minutes',
        'events_value' => ':value events/minute',
    ],

    'notification' => [
        'triggered_title' => 'Ingestion throttled: :project',
        'triggered_body' => 'Project “:project” received :observed events within one minute — the threshold is :threshold, the usual volume :baseline per minute. Ingestion is now throttled; further events are counted and dropped until the volume settles.',
        'recovered_title' => 'Throttling ended: :project',
        'recovered_body' => 'Volume in project “:project” has settled and ingestion is running in full again. During the :minutes minutes of throttling, :discarded events were dropped.',
        'released_title' => 'Throttling lifted: :project',
        'released_body' => ':user lifted the throttle on project “:project”; ingestion is running in full again. Up to the last complete minute, :discarded events were dropped.',
        'unknown_user' => 'Someone',
        'minutes' => ':minutes minutes',
        'context_project' => 'Project',
        'context_observed' => 'Observed',
        'context_threshold' => 'Threshold',
        'context_baseline' => 'Usual volume',
        'context_discarded' => 'Dropped',
        'context_duration' => 'Duration',
    ],

    'flash' => [
        'saved' => 'Spike protection has been saved.',
        'released' => 'The throttle has been lifted.',
        'nothing_to_release' => 'Nothing is being throttled right now.',
    ],
];
