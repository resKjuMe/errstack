<?php

// Notation for dates, times and numbers. Evaluated in App\Support\Formats (on
// the server) and in resources/js/shell/i18n.js (in the interface). A new
// language brings its own notation along without a code change.
return [

    // Patterns for Carbon::translatedFormat().
    'date' => 'M j, Y',
    'date_time' => 'M j, Y g:i A',
    'date_time_seconds' => 'M j, Y g:i:s A',

    // BCP 47 tag for Intl in the browser (toLocaleString).
    'intl' => 'en-US',

    'decimal_separator' => '.',
    'thousands_separator' => ',',

    // Run times (App\Support\Formats::duration). The unit follows the order of
    // magnitude — nobody reads "7380 s" as a little over two hours.
    'duration_milliseconds' => ':value ms',
    'duration_seconds' => ':value s',
    'duration_minutes' => ':value min',
    'duration_hours' => ':value h',

];
