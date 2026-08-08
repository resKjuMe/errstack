<?php

// User feedback (app/Http/Controllers/UserReportController,
// app/Support/Feedback, resources/js/shell/pages/feedback).
return [

    'title' => 'Feedback',
    'help' => 'What affected people described themselves — either as a crash report '
        .'attached to a reported error or as standalone feedback through the '
        .'widget. Feedback is the only information here that nobody can measure '
        .'after the fact; it therefore does not count against the event quota.',

    'list' => [
        'empty' => 'No feedback in the selected period.',
        'empty_hint' => 'Feedback arrives through the SDK ("describe what happened") '
            .'or through the bundled widget.',
        'count' => ':count reports',
        'anonymous' => 'No name given',
        'no_email' => 'No address given',
        'received' => 'Received: :value',
        'unlinked' => 'Event :reference — not matched yet',
        'unlinked_hint' => 'The event id is known, but the event itself is not (any '
            .'more): it may have been filtered out, may still be in processing, '
            .'or may have expired.',
        'show_event' => 'Reported event',
    ],

    'filter' => [
        'status' => 'Status',
        'any_status' => 'Any status',
        'assignee' => 'Assignment',
        'any_assignee' => 'Anyone',
        'assigned_to_me' => 'Assigned to me',
        'unassigned' => 'Unassigned',
    ],

    'assign' => [
        'label' => 'Assign to',
        'nobody' => 'Nobody',
    ],

    'status' => [
        'label' => 'Status',
    ],

    'flash' => [
        'status_changed' => 'Status changed to ":status".',
        'assigned' => 'Feedback handed over to :name.',
        'unassigned' => 'Assignment removed.',
    ],

    'errors' => [
        'not_a_member' => 'That person does not belong to the organization this feedback came in for.',
    ],

    'notification' => [
        'title' => 'New feedback in :project',
        'assigned_title' => 'Feedback in :project is assigned to you',
        'context_project' => 'Project',
        'context_kind' => 'Kind',
        'context_name' => 'Name',
        'context_email' => 'Email',
        'context_url' => 'Page',
    ],

    'environment_ignored' => 'The selected environment has no effect here: feedback is '
        .'written by a person, not by an environment.',

];
