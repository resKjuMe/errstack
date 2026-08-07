<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Größenschranken der Datenaufnahme
    |--------------------------------------------------------------------------
    |
    | Beide Grenzen sind nötig, weil sie verschiedene Dinge abwehren:
    |
    |   request  — was über die Leitung kommt, also der noch gepackte Rumpf.
    |              Ohne diese Grenze könnte eine einzelne Anfrage den Speicher
    |              des Prozesses füllen, bevor irgendetwas geprüft wurde.
    |   payload  — was nach dem Entpacken übrig bleibt. Ohne diese Grenze
    |              genügten wenige Kilobyte gut gepackter Nullen, um daraus
    |              Gigabyte zu machen („Zip-Bombe").
    |
    | Die Vorgaben entsprechen Sentry: dort ist eine einzelne Fehlermeldung auf
    | 1 MiB begrenzt. Wer größere Meldungen zulassen will, hebt beide Werte an —
    | die Grenze für den gepackten Rumpf allein zu erhöhen bringt nichts.
    |
    */

    'max_request_bytes' => (int) env('INGEST_MAX_REQUEST_BYTES', 1024 * 1024),

    'max_payload_bytes' => (int) env('INGEST_MAX_PAYLOAD_BYTES', 1024 * 1024),

];
