<?php

// Discover: build a query of your own, look at it as a table and a chart,
// export it.
return [

    'title' => 'Discover',

    'help' => 'Put together an analysis of your own: dataset, grouping, measures, search condition and ordering. Table and chart show the same query — the chart lines are the top rows of the table. The complete state lives in the address bar: reloading keeps it, and a shared link shows the recipient the same analysis.',

    'query' => [
        'dataset' => 'Dataset',
        'group_by' => 'Group by',
        'group_by_none' => 'No grouping',
        'group_by_add' => 'Add field',
        'metrics' => 'Measures',
        'metrics_add' => 'Add measure',
        'metric_field' => 'Field',
        'metric_field_none' => 'no field',
        'search' => 'Search condition',
        'search_placeholder' => 'e.g. level:error environment:production',
        'sort' => 'Ordering',
        'limit' => 'Rows',
        'interval' => 'Interval',
        'submit' => 'Run',
        'reset' => 'Reset',
        'remove' => 'Remove',
    ],

    'notes' => [
        'truncated' => 'There are more groups than shown. You are seeing the top :limit by the chosen ordering.',
        'cached' => 'From the cache — the numbers may be up to a minute old.',
        'unavailable' => 'This dataset has no such fields, they did not narrow anything down: :fields',
        'search_error' => 'The search condition was not understood (position :position): :message',
        'search_error_hint' => 'The analysis is therefore shown unfiltered.',
        'series_limit' => 'The chart shows the top :count rows of the table.',
    ],

    'error' => [
        'title' => 'This analysis was not run',
        'limit' => 'The limit “:limit” is exceeded: :allowed allowed, :given requested.',
        'timeout' => 'The query took longer than allowed (:timeout ms) and was cancelled. A smaller time range or a coarser grouping helps.',
        'unsupported' => 'The dataset cannot compute this measure: :what',
        'unknown_field' => 'The field “:field” does not exist in this dataset.',
        'invalid' => ':message',
    ],

    'table' => [
        'empty' => 'No data in the selected time range.',
        'empty_hint' => 'A larger time range, a different dataset or a less narrow search condition may show something.',
        'missing' => 'no value',
        'rows' => ':count rows',
        'drilldown' => 'To the underlying events',
        'no_drilldown' => 'There is no event list for this row that shows exactly the same set.',
    ],

    'chart' => [
        'all' => 'All',
        'title' => 'Over time',
        'metric' => 'Measure in the chart',
        'empty' => 'Nothing to draw.',
        'label' => 'The selected measure over time',
        'total' => 'Total: :total',
    ],

    'export' => [
        'action' => 'Export as CSV',
        'filename' => 'discover-:dataset-:date.csv',
    ],

    'project' => [
        'required' => 'Select exactly one project.',
        'reason' => 'A free-form analysis is one query over one body of data, and its limits — time, rows, data points — apply per query. Across several projects it would be one query per project: the limit would then apply to none of them. Measures such as a percentile or apdex could not be added up afterwards anyway.',
        'choose' => 'Choose a project:',
        'none' => 'This organization has no project yet.',
        'current' => 'Analysing :project.',
    ],

    'saved' => [
        'title' => 'Saved',
        'empty' => 'No saved analysis yet.',
        'save' => 'Save analysis',
        'save_hint' => 'What gets saved is what is set right now: dataset, grouping, measures, search condition, sorting, row count and step size — plus period, environment and project.',
        'manage' => 'Manage',
        'name' => 'Name',
        'name_placeholder' => 'e.g. Errors by browser',
        'description' => 'Description',
        'description_placeholder' => 'What is this analysis for?',
        'shared' => 'Share with the organization',
        'shared_hint' => 'Shared means everyone in this organization can see the analysis. Changing and deleting it is still up to whoever created it — anyone who needs it as a starting point duplicates it.',
        'shared_by' => 'Shared by :name',
        'submit' => 'Save',
        'cancel' => 'Cancel',
        'delete' => 'Delete',
        'duplicate' => 'Duplicate',
        'confirm_delete' => 'Delete this saved analysis?',
        'copy_name' => ':name (copy)',
        'restores_period' => 'Opens with the saved period — changeable in the filter bar afterwards.',

        'widget' => [
            'action' => 'Add as widget',
            'dashboard' => 'Dashboard',
            'type' => 'Display',
            'submit' => 'Add',
            'none' => 'You do not have a dashboard of your own yet. Create one, then this analysis can be added to it.',
            'hint' => 'The widget gets a copy of the question. The saved period does not come along: on a dashboard its own filter bar applies.',
        ],

        'errors' => [
            'too_many' => 'More than :limit saved analyses per organization is not possible. Delete one you no longer need.',
        ],

        'flash' => [
            'created' => 'Analysis saved.',
            'updated' => 'Analysis changed.',
            'duplicated' => 'Analysis duplicated.',
            'deleted' => 'Analysis deleted.',
            'widget_created' => 'Added as a widget on “:dashboard”.',
        ],
    ],

];
