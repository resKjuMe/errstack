<?php

// Issue alert rules (app/Http/Controllers/IssueAlertRuleController,
// app/Support/IssueAlerts, resources/js/shell/pages/projects/IssueAlerts.jsx).
return [

    'title' => 'Alert rules — :project',
    'help' => 'A rule has three parts: a trigger ("a new issue"), any number of '
        .'filters ("only level error, only production") and an action ("send to '
        .'Slack"). It is checked right after each incoming event has been '
        .'processed. The rate limit makes sure the same issue notifies at most '
        .'once per window — otherwise an outage would fill an inbox with the same '
        .'message.',

    'intro' => [
        'title' => 'How a rule works',
        'description' => 'The trigger decides when to look at all; the filters take away '
            .'from that. Without a filter the rule applies to every issue the trigger '
            .'matches.',
        'pending_hint' => 'Assignment and ticket creation as actions arrive with issue '
            .'ownership and the ticket integrations; until then the organisation\'s '
            .'notification channels and personal notifications are available.',
    ],

    'list' => [
        'empty' => 'No rule set up yet.',
        'inactive_badge' => 'disabled',
        'subtitle' => 'at most one notification per issue every :minutes minutes',
        'conditions' => 'Trigger',
        'filters' => 'Filters',
        'no_filters' => 'none',
        'actions' => 'Actions',
        'triggers' => 'Fired',
    ],

    'create' => [
        'title' => 'Create a rule',
        'description' => 'At least one trigger and one action are required — a rule '
            .'without both would sit there doing nothing.',
    ],

    'fields' => [
        'name' => 'Name',
        'condition_match' => 'Trigger matches when',
        'filter_match' => 'Filter matches when',
        'frequency' => 'At most one notification per issue every (minutes, :min–:max)',
        'value' => 'Count',
        'window_minutes' => 'Window (minutes)',
        'window_hours' => 'Window (hours)',
        'comparison' => 'Comparison',
        'filter_value' => 'Value',
        'tag_key' => 'Tag',
        'channel' => 'Channel',
        'all_channels' => 'All active channels',
        'channel_inactive' => '(disabled)',
        'add_condition' => 'Add trigger',
        'add_filter' => 'Add filter',
        'add_action' => 'Add action',
        'remove' => 'Remove',
    ],

    'actions' => [
        'create' => 'Create rule',
        'save' => 'Save',
        'preview' => 'Preview',
        'previewing' => 'Checking …',
        'enable' => 'Enable',
        'disable' => 'Disable',
        'delete' => 'Delete',
    ],

    'preview' => [
        'caption' => 'Issues from the last :days days this rule would currently match.',
        'empty' => 'No issue from the last :days days would match this rule.',
        'summary' => ':matched of :scanned issues checked.',
        'truncated' => 'Only the first :limit are shown.',
        'reasons' => 'Trigger:',
        'note' => 'The preview does not replay history, it checks today\'s state: "a new '
            .'issue" means "first seen within the last :days days" here. The rate limit is '
            .'left out.',
    ],

    'history' => [
        'title' => 'Alert history',
        'description' => 'What fired when — across all rules of this project.',
        'empty' => 'Nothing has fired yet.',
        'deliveries' => ':count deliveries',
        'no_deliveries' => 'no delivery — no active channel',
    ],

    'notification' => [
        'title' => ':rule — :project',
        'body' => ':title (:reason)',
        'untitled' => 'Untitled issue',
        'context_project' => 'Project',
        'context_rule' => 'Rule',
        'context_reason' => 'Trigger',
        'context_level' => 'Level',
        'context_times_seen' => 'Seen so far',
        'context_environment' => 'Environment',
        'context_release' => 'Release',
    ],

    'flash' => [
        'created' => 'Rule ":name" created.',
        'updated' => 'Rule ":name" saved.',
        'enabled' => 'Rule ":name" enabled.',
        'disabled' => 'Rule ":name" disabled.',
        'deleted' => 'Rule ":name" deleted.',
    ],

    'validation' => [
        'too_many' => 'More than :max rules per project is not possible.',
        'value_required' => 'This trigger needs a count greater than zero.',
        'window_range' => 'The window must be between 1 and :max.',
        'comparison_invalid' => 'This comparison does not fit this filter.',
        'key_required' => 'This filter needs a tag name.',
        'filter_value_required' => 'This filter needs a value.',
        'filter_value_numeric' => 'This filter needs a number.',
        'channel_unknown' => 'This channel does not belong to this organisation.',
    ],

];
