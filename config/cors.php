<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS)
    |--------------------------------------------------------------------------
    |
    | Die Datenaufnahme wird aus fremden Seiten heraus aufgerufen: das
    | JavaScript-SDK meldet Fehler direkt aus dem Browser des Besuchers, also
    | von jeder Adresse, unter der eine überwachte Anwendung läuft. Welche das
    | sind, weiß diese Installation nicht — deshalb `*` als erlaubte Herkunft.
    | Gefährlich ist das nicht: die Aufnahme braucht keine Sitzung und liest
    | keine Daten heraus, sie nimmt nur an. Aus demselben Grund bleibt
    | `supports_credentials` aus; mit `*` wäre es ohnehin unzulässig.
    |
    | `exposed_headers` ist der Grund, warum diese Datei überhaupt existiert:
    | ohne die Freigabe kommt `X-Sentry-Error` im Browser nicht an, und ein
    | abgewiesener Aufruf wäre dort nicht mehr von einem Netzfehler zu
    | unterscheiden.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['X-Sentry-Error', 'Retry-After'],

    // Vorab-Anfrage (OPTIONS) eine Stunde gültig. Ohne Frist stellt der Browser
    // sie vor jeder einzelnen Fehlermeldung erneut — doppelt so viele Aufrufe
    // für dieselbe Menge Daten.
    'max_age' => 3600,

    'supports_credentials' => false,

];
