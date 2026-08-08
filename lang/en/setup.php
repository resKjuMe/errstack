<?php

// The setup wizard (O8): from a new project to the first error. The example
// code itself is not here but in App\Support\Setup\SetupGuide — it is not
// translated.
return [
    'title' => 'Setup: :project',
    'help' => 'Pick the technology of your application, take the example with your DSN and trigger a test error. As soon as the first event arrives here, this page says so by itself — no reload needed.',
    'to_settings' => 'Settings',
    'copy' => 'Copy',
    'copied' => 'Copied',

    'card' => [
        'title' => 'Setup',
        'description' => 'Instructions and example code for connecting an application — available again at any time.',
        'open' => 'Open wizard',
    ],

    'platform' => [
        'title' => '1. Pick a technology',
        'description' => 'The choice only selects the example. A project may receive events from several applications — the platform in the settings stays as it is.',
    ],

    'code' => [
        'title' => '2. Add the SDK — :guide',
        'description' => 'The official SDK :package, unchanged. The DSN is already filled in.',
        'dsn' => 'DSN (key “:key”)',
        'install' => 'Install',
        'configure' => 'Configure',
        'verify' => 'Trigger a test error',
        'official' => 'Errstack accepts events from the original SDKs; nothing about them is patched.',
        'docs' => ':package documentation',
    ],

    'waiting' => [
        'title' => '3. Waiting for the first event',
        'description' => 'This page notices the first event by itself.',
        'hint' => 'Nothing yet. Trigger the test error from step 3 — the display changes on its own.',
        'spinner' => 'Waiting for the first event',
    ],

    'received' => [
        'title' => 'Event received',
        'description' => 'The first event of this project arrived at :time.',
        'description_sdk' => 'The first event of this project arrived at :time, sent by :sdk.',
        'open' => 'View the error',
        'processing' => 'The event is being processed. The error will show up here — and in the issue list — in a moment.',
        'to_issues' => 'To the issue list',
    ],

    'help_section' => [
        'open' => 'Nothing arriving?',
        'title' => 'Nothing is arriving',
        'description' => 'The most common causes when nothing shows up after the test error.',
        'to_keys' => 'Check client keys',
        'to_docs' => ':package documentation',
        'causes' => [
            'dsn' => 'The DSN does not match — it has to be character-for-character the one from step 2. A second place of configuration (environment variable, .env, build setting) tends to override it silently.',
            'reachable' => 'The application cannot reach this installation: firewall, proxy, or a host name that only your browser resolves and the application server does not.',
            'flush' => 'The process finished before the event was sent. Short-lived scripts, console commands and lambda functions need a final flush.',
            'sample_rate' => 'A sample rate dropped the event. For a test, error and `traces_sample_rate` belong at 1.0; a `before_send` returning null discards silently as well.',
            'key_disabled' => 'The key in use is disabled or has been rotated — then every event still carrying the old one is rejected.',
            'filters' => 'An inbound filter or a privacy rule of the project sorted the event out. What was sorted out is listed above.',
        ],
        'discards' => [
            'title' => 'Something arrived — it was not kept',
            'description' => 'Events of this project were discarded within the last 24 hours. So the connection works; what is missing is the reason.',
            'entry' => ':count × :reason (:origin)',
            'origin' => [
                'server' => 'rejected by Errstack',
                'client' => 'discarded by the SDK itself',
            ],
        ],
    ],

    'no_key' => [
        'title' => 'No active key',
        'description' => 'This project has no active client key and therefore no DSN to report to.',
        'to_keys' => 'Manage keys',
    ],

    'guides' => [
        'php-laravel' => ['label' => 'Laravel'],
        'php' => ['label' => 'PHP'],
        'javascript-browser' => ['label' => 'JavaScript (browser)'],
        'javascript-react' => ['label' => 'React'],
        'node' => ['label' => 'Node.js'],
        'python' => ['label' => 'Python'],
    ],
];
