<?php

// Abweisungen der Datenaufnahme (App\Exceptions\IngestRejection und ihre
// Aufrufer). Sie gehen als Fehlertext an das meldende SDK.
return [

    'unauthorized' => 'Der Client-Schlüssel ist unbekannt oder gehört nicht zu diesem Projekt.',
    'too_large' => 'Die Meldung ist zu groß — erlaubt sind :bytes Byte.',
    'not_json' => 'Die Meldung ist kein JSON-Objekt.',
    'no_content' => 'Die Meldung hat keinen Inhalt.',
    'not_decodable' => 'Die Meldung ließ sich nicht entpacken.',
    'feedback_incomplete' => 'Die Rückmeldung hat keinen Text — ohne Beschreibung ist sie keine.',
    'envelope_header' => 'Der Envelope beginnt nicht mit einer Kopfzeile aus JSON.',
    'security_unknown' => 'Kein bekannter Sicherheitsbericht — erwartet werden csp-report, expect-ct-report oder expect-staple-report.',

];
