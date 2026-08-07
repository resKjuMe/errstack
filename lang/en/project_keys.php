<?php

// Client keys of a project (resources/js/shell/pages/projects/Keys.jsx and
// App\Http\Controllers\ProjectKeyController).
return [

    'title' => 'Client keys · :project',
    'help' => 'The DSN carries the public key and the project number — an SDK needs nothing more. Separate environments or several applications are worth a key each: if one leaks, it can be switched off without taking the others down.',

    'disabled_badge' => 'switched off',
    'active_description' => 'Reports using this DSN are accepted.',
    'inactive_description' => 'Reports using this DSN are rejected.',

    'copy' => 'Copy',
    'copied' => 'Copied',

    'name' => 'Name',
    'name_hint' => 'Only to tell them apart, by environment or application for instance.',
    'limit' => 'Quota (reports per minute)',
    'limit_hint' => 'Leaving it empty means unlimited. Takes effect with data ingestion.',
    'limit_placeholder' => 'unlimited',
    'save' => 'Save',

    'disable' => 'Switch off',
    'enable' => 'Switch back on',
    'rotate' => 'Regenerate',
    'delete' => 'Delete',
    'rotate_hint' => '"Regenerate" swaps the key inside the DSN — the previous one stops working and has to be replaced everywhere.',

    'create' => [
        'title' => 'Create another key',
        'description' => 'One key per environment or application — then switching one off only affects the one that is concerned.',
        'submit' => 'Create key',
    ],

    'flash' => [
        'created' => 'Key ":name" created.',
        'updated' => 'Key saved.',
        'enabled' => 'Key ":name" is active again.',
        'disabled' => 'Key ":name" is switched off — reports using it are rejected.',
        'rotated' => 'New DSN generated — the previous one no longer works.',
        'deleted' => 'Key ":name" deleted.',
        'last_key' => 'The last key cannot be deleted — switch it off instead.',
    ],

];
