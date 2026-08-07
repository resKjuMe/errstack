<?php

// Abweisungen der Datenaufnahme (App\Exceptions\IngestRejection und ihre
// Aufrufer). Sie gehen als Fehlertext an das meldende SDK.
return [

    'unauthorized' => 'Der Client-Schlüssel ist unbekannt oder gehört nicht zu diesem Projekt.',
    'too_large' => 'Die Meldung ist zu groß — erlaubt sind :bytes Byte.',
    'not_json' => 'Die Meldung ist kein JSON-Objekt.',
    'no_content' => 'Die Meldung hat keinen Inhalt.',
    'not_decodable' => 'Die Meldung ließ sich nicht entpacken.',
    'envelope_header' => 'Der Envelope beginnt nicht mit einer Kopfzeile aus JSON.',

];
