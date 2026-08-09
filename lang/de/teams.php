<?php

// Team-Seite (resources/js/shell/pages/teams/Show.jsx) und die Meldungen aus
// App\Http\Controllers\TeamController und TeamMemberController.
return [

    'overview' => 'Team-Sicht',

    'help' => 'Teams bündeln Mitglieder innerhalb einer Organisation. Rechte hängen weiterhin an der Rolle in der Organisation, nicht am Team.',

    'settings' => [
        'title' => 'Stammdaten',
        'description' => 'Der Name des Teams.',
        'name' => 'Name',
        'submit' => 'Speichern',
    ],

    'members' => [
        'title' => 'Mitglieder',
        'description' => 'Wer zu diesem Team gehört.',
        'empty' => 'Noch niemand zugeordnet.',
        'remove' => 'Entfernen',
        'add' => 'Mitglied hinzufügen',
        'choose' => 'Bitte wählen',
        'submit' => 'Hinzufügen',
        'all_assigned' => 'Alle Mitglieder der Organisation gehören bereits zu diesem Team.',
    ],

    'delete' => [
        'title' => 'Team löschen',
        'description' => 'Die Mitglieder bleiben in der Organisation.',
        'submit' => 'Team löschen',
    ],

    'flash' => [
        'created' => 'Team „:name" angelegt.',
        'updated' => 'Team gespeichert.',
        'deleted' => 'Team „:name" gelöscht.',
        'member_added' => ':name gehört jetzt zu „:team".',
        'member_removed' => ':name gehört nicht mehr zu „:team".',
    ],

];
