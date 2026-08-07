<?php

// Änderungsprotokoll (resources/js/shell/pages/organizations/AuditLog.jsx und
// App\Http\Controllers\AuditLogController).
return [

    'title' => 'Änderungsprotokoll',
    'help' => 'Jede Verwaltungsaktion hinterlässt hier einen Eintrag: wer sie ausgeführt hat, wann, von welcher Adresse aus und was sich dabei geändert hat. Einträge sind unveränderlich — sie verschwinden nur mit der Aufbewahrungsfrist.',

    'filter' => [
        'title' => 'Filter',
        'description' => 'Wirkt auf die Anzeige und auf den Export.',
        'actor' => 'Nutzer',
        'action' => 'Art',
        'from' => 'Von',
        'to' => 'Bis',
        'all' => 'Alle',
        'submit' => 'Filtern',
        'reset' => 'Zurücksetzen',
        'export' => 'Als CSV exportieren',
    ],

    'fields' => [
        'role' => 'Rolle',
        'name' => 'Name',
        'member' => 'Mitglied',
    ],

    'empty' => 'Keine Einträge für diese Auswahl.',

    'export' => [
        // Der Dateiname landet in der Ablage des Betrachters — deshalb ebenfalls
        // in seiner Sprache.
        'filename' => 'protokoll-:organization-:date.csv',
        'columns' => [
            'occurred_at' => 'Zeitpunkt',
            'actor' => 'Nutzer',
            'email' => 'E-Mail',
            'action' => 'Aktion',
            'subject' => 'Betroffen',
            'changes' => 'Änderungen',
            'ip' => 'IP-Adresse',
        ],
    ],

];
