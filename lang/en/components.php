<?php

// The "Components" showcase page and the text of the shared building blocks
// (toast, live demo).
return [

    'title' => 'Components',
    'help' => 'Every building block once in action — to check light/dark mode and behaviour.',

    'flash' => [
        'title' => 'Messages (flash)',
        'description' => 'Read from the session and shown at the top of the content.',
        'example_status' => 'The changes have been saved.',
        'example_error' => 'The operation could not be completed.',
        'example_name_error' => 'The name is required.',
        'example_email_error' => 'The email address is invalid.',
    ],

    'toasts' => [
        'title' => 'Toasts',
        'description' => 'Short-lived feedback in the bottom corner, independent of the page content.',
        'success' => 'Success',
        'error' => 'Error',
        'info' => 'Notice',
        'example_success' => 'The changes have been saved.',
        'example_error' => 'Unfortunately that did not work.',
        'example_info' => 'Noted.',
        'dismiss' => 'Dismiss message',
    ],

    'skeleton' => [
        'title' => 'Loading placeholders (skeleton)',
        'description' => 'Until the data arrives — the same greys as the rest of the interface.',
    ],

    'live' => [
        'title' => 'Background processing',
        'description' => 'The job runs on the "ingest" queue; its result comes back by broadcast.',
        'dispatch' => 'Queue ingest',
        'fail' => 'Force a failure',
        'dispatch_failed' => 'The job could not be queued.',
        'disabled' => 'Live updates are off: BROADCAST_CONNECTION and the connection details are not set. The job still runs — visible in the worker output.',
        'empty' => 'Nothing has arrived yet — is the worker running?',
    ],

];
