<?php

return [

    'title' => 'Dashboards',
    'help' => 'A dashboard is a collection of questions, not a snapshot of numbers: every widget stores its query and recalculates when you open it. Time range, environment and project come from the filter bar above — individual widgets may deviate, and say so when they do.',

    'list' => [
        'empty' => 'No dashboard yet. Start from a template or create an empty one.',
        'widgets' => ':count widgets',
        'widgets_one' => 'one widget',
        'shared' => 'shared',
        'owner' => 'by :name',
        'updated' => 'changed :at',
        'open' => 'Open',
    ],

    'create' => [
        'title' => 'New dashboard',
        'name' => 'Name',
        'description' => 'Description',
        'template' => 'Template',
        'template_none' => 'Empty — add widgets yourself',
        'shared' => 'Share with the whole organization',
        'submit' => 'Create',
    ],

    'settings' => [
        'title' => 'Dashboard',
        'submit' => 'Save',
        'delete' => 'Delete',
        'delete_confirm' => 'Delete this dashboard and its widgets?',
        'duplicate' => 'Duplicate',
        'shared_hint' => 'Shared means seeing, not changing: only its creator can still change it.',
        'readonly' => 'This dashboard belongs to :name. You can view and duplicate it, but not change it.',
    ],

    'grid' => [
        'empty' => 'No widget yet. Add the first one.',
        'add' => 'Add widget',
        'full' => 'This dashboard holds the maximum number of widgets (:limit).',
        'move' => 'Move widget',
        'resize' => 'Resize',
        'keyboard_hint' => 'Move with the arrow keys, resize with shift + arrow keys.',
    ],

    'widget' => [
        'add' => 'Add widget',
        'edit' => 'Edit widget',
        'delete' => 'Delete widget',
        'delete_confirm' => 'Delete this widget?',
        'submit' => 'Save',
        'cancel' => 'Cancel',
        'title' => 'Heading',
        'type' => 'Display',
        'dataset' => 'Data source',
        'fields' => 'Group by',
        'field_none' => '— no field —',
        'metrics' => 'Metrics',
        'metric_field' => 'Field',
        'search' => 'Condition',
        'search_placeholder' => 'e.g. level:error',
        'sort' => 'Sorting',
        'sort_placeholder' => 'e.g. -count',
        'limit' => 'Rows',
        'interval' => 'Step',
        'interval_auto' => 'automatic (matching the time range)',
        'explore' => 'Open in Discover',

        'overrides' => [
            'title' => 'This widget’s own view',
            'hint' => 'Empty means the filter bar above applies.',
            'period' => 'Time range',
            'period_inherit' => '— as the filter bar —',
            'from' => 'From',
            'to' => 'To',
            'environment' => 'Environment',
            'environment_inherit' => '— as the filter bar —',
            'project' => 'Project',
            'project_inherit' => '— as the filter bar —',
        ],

        'loading' => 'loading …',
        'empty' => 'No data in the selected time range.',
        'cached' => 'from the cache',
        'truncated' => 'cut off at :limit rows',
        'scope' => 'own range: :range',

        'error' => [
            'title' => 'Not calculated',
            'project_required' => 'This widget needs exactly one project. Select one above, or set one on the widget.',
            'project_missing' => 'The project “:project” of this widget no longer exists, or you cannot see it.',
            'retry' => 'Try again',
            'failed' => 'The numbers of this widget could not be loaded.',
        ],

        'map' => [
            'missing_field' => 'A world map needs a grouping by country (:field for errors, country for response times).',
            'unknown' => 'without country',
            'countries' => ':count countries',
        ],

        'number' => [
            'no_value' => 'no value',
        ],
    ],

    'templates' => [
        'errors' => [
            'name' => 'Error overview',
            'description' => 'How many errors, which ones, who they hit and with what.',
            'widgets' => [
                'volume' => 'Errors over time',
                'total' => 'Errors total',
                'users' => 'Users affected',
                'top' => 'Most frequent errors',
                'browsers' => 'By browser',
            ],
        ],
        'performance' => [
            'name' => 'Performance',
            'description' => 'Response times, satisfaction and the slowest calls.',
            'widgets' => [
                'p95' => 'Response time p95',
                'apdex' => 'Satisfaction',
                'failure_rate' => 'Failure rate',
                'slowest' => 'Slowest calls',
                'countries' => 'Response time by country',
            ],
        ],
        'release_health' => [
            'name' => 'Release health',
            'description' => 'What the new version brings — errors per release.',
            'widgets' => [
                'by_release' => 'Errors per release',
                'releases' => 'Releases in range',
                'fatal' => 'Fatal errors',
                'table' => 'Releases compared',
            ],
        ],
    ],

    'copy_name' => ':name (copy)',

    'flash' => [
        'created' => 'Dashboard created.',
        'updated' => 'Dashboard saved.',
        'deleted' => 'Dashboard deleted.',
        'duplicated' => 'Dashboard duplicated.',
        'widget_created' => 'Widget added.',
        'widget_updated' => 'Widget saved.',
        'widget_deleted' => 'Widget deleted.',
        'layout_saved' => 'Arrangement saved.',
    ],

    'errors' => [
        'too_many' => 'More than :limit dashboards per organization are not supported.',
        'too_many_widgets' => 'More than :limit widgets per dashboard are not supported.',
    ],

];
