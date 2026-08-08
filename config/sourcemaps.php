<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ablage der Release-Artefakte
    |--------------------------------------------------------------------------
    |
    | Hochgeladene Bundles und Quellkarten liegen nicht in der Datenbank, sondern
    | auf einer Platte: eine Quellkarte eines echten Bundles ist mehrere Megabyte
    | groß, und die Datenbank ist der falsche Ort für etwas, das nur als Ganzes
    | gelesen wird.
    |
    | Der Ablageort ist ein Laufwerk aus `config/filesystems.php` und damit
    | austauschbar — im Betrieb ein Objektspeicher, in der Entwicklung die lokale
    | Platte, im Test ein vorgetäuschtes Laufwerk.
    |
    | Der Ablagepfad ist der Prüfsummenpfad (`sha1` des Inhalts). Zwei Uploads
    | derselben Datei belegen damit einmal Platz — bei einer
    | Auslieferungs-Pipeline, die nach einem Fehlschlag noch einmal läuft, ist das
    | der Regelfall und nicht der Ausnahmefall.
    |
    */

    'disk' => env('SOURCEMAPS_DISK', 'local'),

    'path' => env('SOURCEMAPS_PATH', 'release-artifacts'),

    /*
    |--------------------------------------------------------------------------
    | Grenzen
    |--------------------------------------------------------------------------
    |
    | Zwei Grenzen, und beide sind nötig, weil sie verschiedene Fälle abfangen.
    |
    |   max_file_bytes        — wie groß eine einzelne Datei sein darf. Eine
    |                           Quellkarte mit eingebettetem Quelltext
    |                           (`sourcesContent`) ist um ein Mehrfaches größer
    |                           als das Bundle, zu dem sie gehört; 60 MB sind
    |                           deshalb kein Tippfehler, sondern der Platz für
    |                           genau eine solche Datei.
    |   max_files_per_release — wie viele Dateien eine Version tragen darf. Die
    |                           Zahl schützt nicht vor einer großen Auslieferung,
    |                           sondern vor einer Pipeline, die in einer Schleife
    |                           hochlädt: 2000 Dateien je Version reichen für
    |                           jedes Bundle, das ein Mensch noch lesen will.
    |
    | Beide werden beim Hochladen geprüft und als Prüffehler abgewiesen — nicht
    | mit einer eigenen Antwortform. Ein Client, der Prüffehler auswertet, soll
    | für diesen Fall keinen zweiten Zweig brauchen.
    |
    */

    'max_file_bytes' => (int) env('SOURCEMAPS_MAX_FILE_BYTES', 60 * 1024 * 1024),

    'max_files_per_release' => (int) env('SOURCEMAPS_MAX_FILES_PER_RELEASE', 2000),

    /*
    |--------------------------------------------------------------------------
    | Rückübersetzung
    |--------------------------------------------------------------------------
    |
    |   context_lines — wie viele Zeilen vor und nach der Fehlerstelle aus dem
    |                   eingebetteten Quelltext geholt werden. Fünf wie bei den
    |                   SDKs, die den Ausschnitt selbst mitschicken: derselbe
    |                   Ausschnitt, damit eine zurückübersetzte Stelle nicht
    |                   anders aussieht als eine, die schon lesbar ankam.
    |   max_frames    — wie viele Rahmen je Meldung überhaupt angefasst werden.
    |                   Ein Stacktrace aus einer Endlos-Rekursion hat hunderte,
    |                   und jeder davon ist eine Suche in der Quellkarte. Die
    |                   Grenze gilt für die Meldung als Ganzes, nicht je Ausnahme.
    |   max_map_bytes — wie groß eine Quellkarte sein darf, um sie zum
    |                   Übersetzen in den Speicher zu holen. Kleiner als
    |                   `max_file_bytes`: hochladen darf man auch, was sich nur
    |                   noch aufbewahren lässt.
    |
    */

    'context_lines' => (int) env('SOURCEMAPS_CONTEXT_LINES', 5),

    'max_frames' => (int) env('SOURCEMAPS_MAX_FRAMES', 200),

    'max_map_bytes' => (int) env('SOURCEMAPS_MAX_MAP_BYTES', 40 * 1024 * 1024),

];
