<?php

// Die Versionsliste (app/Http/Controllers/ReleaseController,
// resources/js/shell/pages/releases).
return [

    'title' => 'Versionen',
    'help' => 'Die ausgelieferten Versionen der gewählten Projekte. Eine Version '
        .'entsteht von selbst, sobald eine Meldung sie mitbringt, und lässt sich '
        .'zusätzlich beim Ausliefern über die Schnittstelle ankündigen. „Neu" sind '
        .'die Fehler, die in dieser Version zum ersten Mal auftraten — die Zahl, '
        .'an der man sieht, ob eine Auslieferung etwas mitgebracht hat.',

    'list' => [
        'empty' => 'Keine Versionen im gewählten Zeitraum.',
        'empty_hint' => 'Sobald eine Meldung eine Version mitbringt, steht sie hier.',
        'count' => ':count Versionen',
        'first_event' => 'Zuerst gesehen: :value',
        'no_events' => 'Noch keine Meldung',
        'released_at' => 'Ausgeliefert: :value',
    ],

    'columns' => [
        'version' => 'Version',
        'new' => 'Neue Fehler',
        'resolved' => 'Davon erledigt',
        'resolved_hint' => 'Von den in dieser Version neu aufgetretenen Fehlern sind so '
            .'viele inzwischen erledigt. Ob sie in dieser Version behoben wurden, '
            .'sagt die Zahl nicht.',
        'last_event' => 'Zuletzt gesehen',
    ],

    'unordered' => 'ohne Rangfolge',
    'unordered_hint' => 'Diese Versionsangabe lässt sich nicht als Nummer lesen '
        .'(etwa ein Commit-Hash). Sie steht deshalb nach den nummerierten '
        .'Versionen, sortiert nach Zeit.',

    'environment_ignored' => 'Die gewählte Umgebung wirkt hier nicht: eine Version wird '
        .'als Ganzes ausgeliefert und gehört nicht zu einer Umgebung.',

];
