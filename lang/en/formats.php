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

];
