<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Version der öffentlichen Schnittstelle
    |--------------------------------------------------------------------------
    |
    | Die Schnittstelle liegt unter `/api/0/` — dieselbe Versionierung wie bei
    | Sentry, damit vorhandene Werkzeuge (sentry-cli, SDKs) unverändert damit
    | sprechen können. Eine neue Version bekäme ein eigenes Präfix, die alte
    | bliebe daneben bestehen.
    |
    */

    'version' => '0',

    /*
    |--------------------------------------------------------------------------
    | Blätterung
    |--------------------------------------------------------------------------
    |
    | `per_page` ist frei wählbar, aber gedeckelt: ohne Obergrenze könnte ein
    | einzelner Aufruf die gesamte Tabelle ziehen.
    |
    */

    'pagination' => [
        'per_page' => 50,
        'max_per_page' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Ratenbegrenzung
    |--------------------------------------------------------------------------
    |
    | Gilt je Token (ohne Token je Absender-Adresse) und liefert bei Überschrei-
    | tung 429 samt `Retry-After`. Projekt- und mengenbezogene Kontingente sind
    | etwas anderes und kommen mit O1.
    |
    */

    'rate_limit' => [
        'max_attempts' => (int) env('API_RATE_LIMIT', 60),
        'decay_minutes' => 1,
    ],

];
