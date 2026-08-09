<?php

// Die Versionsliste (app/Http/Controllers/ReleaseController,
// resources/js/shell/pages/releases).
return [

    'title' => 'Versionen',
    'help' => 'Die ausgelieferten Versionen der gewählten Projekte. Eine Version '
        .'entsteht von selbst, sobald eine Meldung sie mitbringt, und lässt sich '
        .'zusätzlich beim Ausliefern über die Schnittstelle ankündigen. „Neu" sind '
        .'die Fehler, die in dieser Version zum ersten Mal auftraten — die Zahl, '
        .'an der man sieht, ob eine Auslieferung etwas mitgebracht hat. Daneben '
        .'stehen Gesundheit und Verbreitung: wie viele Sitzungen dieser Version '
        .'abstürzen und wie weit sie schon ausgerollt ist.',

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
        'crash_free' => 'Absturzfrei',
        'crash_free_hint' => 'Der Anteil der Sitzungen dieser Version, die nicht '
            .'abgestürzt sind — im gewählten Zeitraum und in der gewählten Umgebung. '
            .'Fehler und Abbrüche zählen dabei nicht als Absturz.',
        'adoption' => 'Verbreitung',
        'adoption_hint' => 'Der Anteil der Sitzungen des Projekts, die im gewählten '
            .'Zeitraum auf diese Version entfielen. Wer im Zeitraum zwei Versionen '
            .'benutzt hat, zählt in beiden — die Anteile ergeben deshalb nicht '
            .'zwingend hundert Prozent.',
        'last_event' => 'Zuletzt gesehen',
    ],

    'sort' => [
        'label' => 'Sortierung',
        'newest' => 'Neueste zuerst',
        'oldest' => 'Älteste zuerst',
        'new_issues' => 'Meiste neue Fehler',
        'crash_free' => 'Schlechteste Crash-Free-Rate',
        'adoption' => 'Höchste Verbreitung',
    ],

    // Die Kennzahlen aus den Sitzungsdaten (R7), wie sie in Liste und
    // Detailseite auftauchen.
    'health' => [
        'title' => 'Gesundheit',
        'help' => 'Wie viele Sitzungen und Menschen diese Version überstanden haben. '
            .'Die Zahlen kommen aus den Sitzungsmeldungen des SDK und gelten für den '
            .'gewählten Zeitraum und die gewählte Umgebung.',
        'crash_free_sessions' => 'Absturzfreie Sitzungen',
        'crash_free_users' => 'Absturzfreie Nutzer',
        'adoption_sessions' => 'Verbreitung (Sitzungen)',
        'adoption_users' => 'Verbreitung (Nutzer)',
        'sessions' => 'Sitzungen',
        'crashed_sessions' => 'Davon abgestürzt',
        'errored_sessions' => 'Davon mit Fehlern',
        'abnormal_sessions' => 'Davon abgebrochen',
        'users' => 'Menschen',
        'crashed_users' => 'Davon mit Absturz',
        'unknown_hint' => 'Ohne Sitzungen im gewählten Zeitraum lässt sich die Quote '
            .'nicht sagen. „100 %“ stünde hier fälschlich für „alles in Ordnung“.',
        'no_users' => 'Diese Zahl braucht eine Nutzerkennung in den Meldungen. Ohne sie '
            .'bleibt sie leer — und nicht etwa 100 %.',
        'empty' => 'Für diese Version sind im gewählten Zeitraum keine Sitzungen '
            .'eingetroffen.',
        'empty_hint' => 'Sitzungen meldet das SDK gesondert; ohne sie gibt es keine '
            .'Crash-Free-Rate. Eine Version ohne Sitzungen ist nicht gesund, sondern '
            .'unbekannt.',
    ],

    // Der Vergleich zur Vorversion — der eigentliche Zweck der Zahlen.
    'comparison' => [
        'help' => '„99,2 % absturzfrei“ allein sagt nicht, ob die Auslieferung gut war. '
            .'Verglichen wird mit der Version, die in der Liste eine Zeile weiter unten '
            .'steht — im selben Zeitraum und derselben Umgebung.',
        'none' => 'Es gibt keine ältere Version, mit der sich diese vergleichen ließe.',
        'none_hint' => 'Das ist bei der ersten erfassten Auslieferung eines Projekts so.',
        'no_data' => 'Die Vorversion hat im gewählten Zeitraum keine Sitzungen — ohne '
            .'sie gibt es nichts zu vergleichen.',
        'up' => 'besser als :version',
        'down' => 'schlechter als :version',
        'flat' => 'unverändert gegenüber :version',
        'points' => ':value Prozentpunkte',
        'link' => 'Zur Vorversion :version',
    ],

    'adoption' => [
        'title' => 'Verbreitung im Zeitverlauf',
        'help' => 'Der Anteil dieser Version an allen Sitzungen des Projekts. Die Kurve '
            .'beantwortet die Frage, die eine einzelne Zahl offenlässt: steigt das '
            .'Ausrollen noch, oder steht es?',
        'label' => 'Verlauf der Verbreitung',
        'empty' => 'Für diese Version sind im gewählten Zeitraum keine Sitzungen '
            .'eingetroffen.',
        'empty_project' => 'Im gewählten Zeitraum sind für dieses Projekt überhaupt '
            .'keine Sitzungen eingetroffen.',
        'gap_hint' => 'Abschnitte ohne Sitzungen des Projekts unterbrechen die Linie: '
            .'in einer Nacht ohne Verkehr ist die Verbreitung nicht auf null gefallen, '
            .'sie ist unbekannt.',
    ],

    'issues' => [
        'title' => 'Fehler',
        'new' => 'Neu in dieser Version',
        'new_hint' => 'Fehler, die mit dieser Auslieferung zum ersten Mal auftraten.',
        'resolved' => 'In dieser Version erledigt',
        'resolved_hint' => 'Fehler, die beim Ausliefern dieser Version als erledigt '
            .'vermerkt wurden.',
        'regressed' => 'Mit dieser Version zurückgekommen',
        'regressed_hint' => 'Fehler, die bereits erledigt waren und in dieser Version '
            .'wieder auftraten.',
    ],

    'artifacts' => [
        'title' => 'Bauartefakte',
        'help' => 'Die hochgeladenen Bundles und Quellkarten dieser Version. Ohne sie '
            .'bleiben minimierte Stacktraces unlesbar.',
        'count' => ':count Artefakte',
        'truncated' => 'Es werden :shown von :total Artefakten gezeigt.',
        'empty' => 'Für diese Version wurden keine Artefakte hochgeladen.',
        'empty_hint' => 'Ein Bauvorgang lädt sie am Ende hoch; '
            .'POST /api/0/organizations/{org}/projects/{projekt}/releases/{version}/files.',
        'debug_id' => 'Debug-Kennung',
        'debug_id_hint' => 'Dieses Artefakt lässt sich auch ohne passenden Pfad '
            .'zuordnen.',
        'source_map' => 'Quellkarte: :value',
    ],

    'unordered' => 'ohne Rangfolge',
    'unordered_hint' => 'Diese Versionsangabe lässt sich nicht als Nummer lesen '
        .'(etwa ein Commit-Hash). Sie steht deshalb nach den nummerierten '
        .'Versionen, sortiert nach Zeit.',

    'environment_partial' => 'Die gewählte Umgebung entscheidet nicht, welche Versionen '
        .'hier stehen: eine Version wird als Ganzes ausgeliefert und gehört nicht zu '
        .'einer Umgebung. Auf Gesundheit und Verbreitung wirkt sie sehr wohl — '
        .'Sitzungen gehören zu einer Umgebung.',

    // Die Detailseite einer Version (R2): was in ihr steckt.
    'deploys' => [
        'title' => 'Auslieferungen',
        'help' => 'Wann diese Version in welcher Umgebung landete. Der Zeitpunkt '
            .'geht aus keiner Meldung hervor — er kommt aus der Auslieferungs-'
            .'Pipeline über die Schnittstelle.',
        'empty' => 'Für diese Version wurde keine Auslieferung gemeldet.',
        'empty_hint' => 'Eine Pipeline meldet sie am Ende ihres Laufs; '
            .'POST /api/0/organizations/{org}/projects/{projekt}/releases/{version}/deploys.',
        'environment' => 'Umgebung',
        'at' => 'Zeitpunkt',
        'duration' => 'Dauer',
        'link' => 'Zum Baulauf',
        'marker' => 'Ausgeliefert: :version nach :environment (:at)',

        'notification' => [
            'title' => ':version wurde nach :environment ausgeliefert',
            'body' => 'Deine Änderungen sind draußen: :commits Commits stecken in dieser '
                .'Auslieferung von :project.',
            'context_project' => 'Projekt',
            'context_environment' => 'Umgebung',
        ],
    ],

    'detail' => [
        'title' => 'Version :version',
        'help' => 'Was in dieser Auslieferung steckt und wie sie ausgegangen ist. Die '
            .'Commits kommen aus einem verbundenen Repository oder werden beim '
            .'Ausliefern über die Schnittstelle übergeben — eine Version, die nur aus '
            .'Meldungen entstanden ist, hat keine. Gesundheit, Verbreitung und der '
            .'Vergleich zur Vorversion gelten für den gewählten Zeitraum.',
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
