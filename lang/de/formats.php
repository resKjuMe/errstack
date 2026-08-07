<?php

// Schreibweise von Datum, Uhrzeit und Zahlen. Ausgewertet in App\Support\Formats
// (serverseitig) und in resources/js/shell/i18n.js (Oberfläche). Eine neue
// Sprache bringt ihre Schreibweise damit ohne Code-Änderung mit.
return [

    // Muster für Carbon::translatedFormat().
    'date' => 'd.m.Y',
    'date_time' => 'd.m.Y H:i',
    'date_time_seconds' => 'd.m.Y H:i:s',

    // BCP-47-Kennung für Intl im Browser (toLocaleString).
    'intl' => 'de-DE',

    'decimal_separator' => ',',
    'thousands_separator' => '.',

];
