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

    // Laufzeiten (App\Support\Formats::duration). Die Einheit wechselt mit der
    // Größenordnung — „7380 s" liest niemand als gut zwei Stunden.
    'duration_milliseconds' => ':value ms',
    'duration_seconds' => ':value s',
    'duration_minutes' => ':value min',
    'duration_hours' => ':value h',

    // Dateigrößen (App\Support\Formats::bytes). Binär gerechnet, damit eine
    // Datei, die an einer Grenze von „20 MB" scheitert, nicht als 20,9 MB
    // dasteht.
    'bytes' => ':value B',
    'kilobytes' => ':value KB',
    'megabytes' => ':value MB',
    'gigabytes' => ':value GB',

];
