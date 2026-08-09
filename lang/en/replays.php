<?php

// Session replays (resources/js/shell/pages/replays, App\Http\Controllers\ReplayController).
return [

    'title' => 'Replays',
    'detail_title' => 'Replay',

    'help' => [
        'purpose' => 'A replay shows what the user did before the error — clicks, input, page changes. It answers the question neither the stack trace nor the breadcrumbs answer: how did they get there in the first place?',
        'entry' => 'The usual way in is from an error, not from this list: the issue page links the replays in which that exact event occurred.',
        'masking' => 'Masking happens in the browser before anything is sent — text and input fields are replaced by default. What arrives here is already masked; it could not be done after the fact.',
        'retention' => 'Replays are stored separately from event data and deleted after their own, shorter retention period. That period is set in the project privacy settings.',
        'sampling' => 'Only a share of sessions is recorded — the SDK applies its own sample rate. An error without a replay is therefore normal and not a defect.',
    ],

    'list' => [
        'heading' => 'Recorded sessions',
        'columns' => [
            'started' => 'Start',
            'duration' => 'Duration',
            'user' => 'Affected person',
            'url' => 'Entry page',
            'errors' => 'Errors',
            'browser' => 'Browser',
            'environment' => 'Environment',
        ],
        'open' => 'Play replay',
        'only_errors' => 'Only sessions with errors',
        'all_sessions' => 'All sessions',
        'limit' => 'Showing the :limit most recent of :total replays in this period.',
        'total' => ':count replays in this period.',
        'empty' => 'No sessions were recorded for these projects in the selected period.',
        'empty_errors' => 'There is no recorded session with an error in the selected period.',
        'empty_hint' => 'Replays come from the browser SDK replay integration. Until it is set up, this list stays empty even when errors arrive — the project setup guide shows the required call.',
        'ongoing' => 'still running',
        'anonymous' => 'No identifier',
        'more_urls' => '+:count more pages',
    ],

    'player' => [
        'heading' => 'Playback',
        'play' => 'Play',
        'pause' => 'Pause',
        'restart' => 'Restart',
        'speed' => 'Speed',
        'skip_inactive' => 'Skip idle time',
        'loading' => 'Loading replay …',
        'loading_hint' => 'The recording is fetched separately from the page; for a long session this takes a moment.',
        'failed' => 'The replay could not be loaded.',
        'failed_hint' => 'It may have been deleted after its retention period. Reload the page; if it persists, the list shows what else is available.',
        'empty' => 'There is no recording data for this session.',
        'position' => ':position of :duration',
    ],

    'timeline' => [
        'heading' => 'Timeline',
        'hint' => 'Clicking an entry jumps to that point in the recording.',
        'jump' => 'Jump to this point',
        'truncated' => 'Truncated: :count further entries are not shown.',
    ],

    'tracks' => [
        'errors' => 'Errors',
        'breadcrumbs' => 'Breadcrumbs',
        'console' => 'Console',
        'network' => 'Network',
        'empty' => 'Nothing was recorded for this track.',
        'network_columns' => [
            'time' => 'Time',
            'method' => 'Method',
            'description' => 'URL',
            'status' => 'Status',
            'size' => 'Size',
            'duration' => 'Duration',
        ],
    ],

    'meta' => [
        'heading' => 'Session',
        'user' => 'Affected person',
        'browser' => 'Browser',
        'os' => 'Operating system',
        'device' => 'Device',
        'environment' => 'Environment',
        'release' => 'Release',
        'sdk' => 'SDK',
        'started' => 'Start',
        'duration' => 'Duration',
        'segments' => 'Segments',
        'events' => 'Recorded events',
        'size' => 'Storage used',
        'urls' => 'Visited pages',
        'replay_id' => 'Identifier',
        'unknown' => 'Unknown',
    ],

    'masking' => [
        'masked' => 'Masked',
        'masked_hint' => 'The SDK replaced text and input before sending.',
        'unmasked' => 'Not masked',
        'unmasked_hint' => 'This SDK had masking turned off. The replay may contain input and text in the clear — check the setup of the monitored application.',
    ],

    'issue' => [
        'heading' => 'Session replays',
        'hint' => 'What the user did before this event.',
        'open' => 'View replay',
    ],

];
