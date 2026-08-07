<?php

// Audit log (resources/js/shell/pages/organizations/AuditLog.jsx and
// App\Http\Controllers\AuditLogController).
return [

    'title' => 'Audit log',
    'help' => 'Every administrative action leaves an entry here: who performed it, when, from which address and what changed along the way. Entries are immutable — they only disappear with the retention period.',

    'filter' => [
        'title' => 'Filter',
        'description' => 'Applies to the display and to the export.',
        'actor' => 'User',
        'action' => 'Kind',
        'from' => 'From',
        'to' => 'To',
        'all' => 'All',
        'submit' => 'Filter',
        'reset' => 'Reset',
        'export' => 'Export as CSV',
    ],

    'fields' => [
        'role' => 'Role',
        'name' => 'Name',
        'member' => 'Member',
    ],

    'empty' => 'No entries for this selection.',

    'export' => [
        // The file name ends up in the viewer's downloads — so it follows their
        // language as well.
        'filename' => 'audit-log-:organization-:date.csv',
        'columns' => [
            'occurred_at' => 'Time',
            'actor' => 'User',
            'email' => 'Email',
            'action' => 'Action',
            'subject' => 'Subject',
            'changes' => 'Changes',
            'ip' => 'IP address',
        ],
    ],

];
