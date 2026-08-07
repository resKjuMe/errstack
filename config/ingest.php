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

    /*
    |--------------------------------------------------------------------------
    | Envelopes
    |--------------------------------------------------------------------------
    |
    | Ein Envelope bündelt mehrere Elemente in einer Anfrage und ist deshalb
    | zwangsläufig größer als eine einzelne Meldung — ein Screenshot allein
    | sprengt die 1 MiB von oben. Die Grenzen für Einzelmeldungen mit
    | anzuheben, wäre der falsche Weg: die Werte oben schützen den klassischen
    | Weg, auf dem eine so große Meldung nie berechtigt ist.
    |
    | Die Werte greifen an verschiedenen Stellen:
    |
    |   request      — der noch gepackte Rumpf, wie er über die Leitung kommt.
    |   payload      — der ganze Envelope nach dem Entpacken (gegen „Zip-Bomben").
    |   items        — wie viele Elemente ein Envelope tragen darf. Ohne diese
    |                  Grenze wären es in 20 MiB einige Millionen Zeilen, und
    |                  jede kostet eine Einfügung in die Datenbank.
    |   item         — was ein einzelnes JSON-Element wiegen darf; dieselbe
    |                  Grenze wie für eine Fehlermeldung auf dem alten Weg.
    |   attachment   — dasselbe für Anhänge und Aufzeichnungen. Für die ist
    |                  Größe der Normalfall, für eine Fehlermeldung nicht.
    |
    | Ein Element, das seine Grenze reißt, wird für sich verworfen und gezählt —
    | die übrigen kommen an. Nur ein zu großer Envelope im Ganzen wird
    | abgewiesen (413).
    |
    */

    'envelope' => [

        'max_request_bytes' => (int) env('INGEST_ENVELOPE_MAX_REQUEST_BYTES', 20 * 1024 * 1024),

        'max_payload_bytes' => (int) env('INGEST_ENVELOPE_MAX_PAYLOAD_BYTES', 100 * 1024 * 1024),

        'max_items' => (int) env('INGEST_ENVELOPE_MAX_ITEMS', 100),

        'max_item_bytes' => (int) env('INGEST_ENVELOPE_MAX_ITEM_BYTES', 1024 * 1024),

        'max_attachment_bytes' => (int) env('INGEST_ENVELOPE_MAX_ATTACHMENT_BYTES', 20 * 1024 * 1024),

    ],

];
