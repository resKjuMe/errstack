<?php

// Client-Schlüssel eines Projekts (resources/js/shell/pages/projects/Keys.jsx
// und App\Http\Controllers\ProjectKeyController).
return [

    'title' => 'Client-Schlüssel · :project',
    'help' => 'Die DSN enthält den öffentlichen Schlüssel und die Projekt-Nummer — mehr braucht ein SDK nicht. Für getrennte Umgebungen oder mehrere Anwendungen lohnt je ein eigener Schlüssel: Fällt einer aus, lässt er sich abschalten, ohne die übrigen stillzulegen.',

    'disabled_badge' => 'abgeschaltet',
    'active_description' => 'Meldungen mit dieser DSN werden angenommen.',
    'inactive_description' => 'Meldungen mit dieser DSN werden abgewiesen.',

    'copy' => 'Kopieren',
    'copied' => 'Kopiert',

    'name' => 'Name',
    'name_hint' => 'Nur zur Unterscheidung, etwa nach Umgebung oder Anwendung.',
    'limit' => 'Kontingent (Meldungen/Minute)',
    'limit_hint' => 'Leer lassen heißt unbegrenzt. Greift mit der Datenaufnahme.',
    'limit_placeholder' => 'unbegrenzt',
    'save' => 'Speichern',

    'disable' => 'Abschalten',
    'enable' => 'Wieder einschalten',
    'rotate' => 'Neu erzeugen',
    'delete' => 'Löschen',
    'rotate_hint' => '„Neu erzeugen" tauscht den Schlüssel in der DSN aus — die bisherige gilt danach nicht mehr und muss überall ersetzt werden.',

    'create' => [
        'title' => 'Weiteren Schlüssel anlegen',
        'description' => 'Ein eigener Schlüssel je Umgebung oder Anwendung — dann trifft das Abschalten nur den, der es betrifft.',
        'submit' => 'Schlüssel anlegen',
    ],

    'flash' => [
        'created' => 'Schlüssel „:name" angelegt.',
        'updated' => 'Schlüssel gespeichert.',
        'enabled' => 'Schlüssel „:name" ist wieder aktiv.',
        'disabled' => 'Schlüssel „:name" ist abgeschaltet — Meldungen damit werden abgewiesen.',
        'rotated' => 'Neue DSN erzeugt — die bisherige gilt nicht mehr.',
        'deleted' => 'Schlüssel „:name" gelöscht.',
        'last_key' => 'Der letzte Schlüssel lässt sich nicht löschen — schalte ihn stattdessen ab.',
    ],

];
