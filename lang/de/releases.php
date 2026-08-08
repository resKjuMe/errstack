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
        'artifacts' => ':count Artefakte',
        'no_artifacts' => 'Keine Quellkarten',
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

    // Die Detailseite einer Version (R2): was in ihr steckt.
    'detail' => [
        'title' => 'Version :version',
        'help' => 'Was in dieser Auslieferung steckt. Die Commits kommen aus einem '
            .'verbundenen Repository oder werden beim Ausliefern über die '
            .'Schnittstelle übergeben — eine Version, die nur aus Meldungen '
            .'entstanden ist, hat keine.',
        'commits' => 'Commits',
        'commit_count' => ':count Commits',
        'truncated' => 'Es werden :shown von :total Commits gezeigt.',
        'empty' => 'Für diese Version wurden keine Commits übergeben.',
        'empty_hint' => 'Eine Bauumgebung kann sie beim Ausliefern übergeben, auch '
            .'ohne Anbindung an GitHub oder GitLab.',
        'files' => ':count Dateien',
        'no_files' => 'Keine Dateien angegeben',
        'author_unknown' => 'Autor unbekannt',
        'author_member_hint' => 'Diese Adresse gehört zu einem Konto dieser Organisation.',
        'new_issues' => 'Neue Fehler dieser Version',
        'back' => 'Zur Versionsliste',
        'released_at' => 'Ausgeliefert: :value',
        'first_event' => 'Zuerst gesehen: :value',
        'last_event' => 'Zuletzt gesehen: :value',
        'ref' => 'Stand: :value',
    ],

];
