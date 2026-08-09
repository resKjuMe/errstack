<?php

// Die drei Übersichtsseiten (D5): Organisation, Projekt, Team.
// resources/js/shell/pages/Dashboard.jsx, projects/Overview.jsx,
// teams/Overview.jsx und die gemeinsamen Kachel-Bausteine unter
// resources/js/shell/pages/overview/.
return [

    'panel' => [
        'loading' => 'Wird geladen …',
        'empty' => 'In diesem Zeitraum ist hier nichts passiert.',
        'failed' => 'Diese Kachel konnte nicht geladen werden.',
        'retry' => 'Erneut versuchen',
        'unknown' => 'Diese Kachel gibt es nicht.',
        'stale' => 'Diese Zahlen sind vom vorigen Abruf — der neue ist fehlgeschlagen.',
        'truncated' => 'Es zeigt nur einen Teil der Projekte: über mehr fragt die Übersicht nicht ab.',
        'all' => 'Alles ansehen',
    ],

    'setup' => [
        'title' => 'Noch nichts angekommen',
        'description' => 'Von diesen Projekten liegt noch keine Meldung vor. Sie zeigen deshalb keinen Verlauf — ein leeres Diagramm wäre hier keine Auskunft.',
        'action' => 'Projekt einrichten',
        'pending' => 'Wartet auf die erste Meldung',
    ],

    'organization' => [
        'title' => 'Übersicht',
        'description' => 'Was in :name los ist — im gewählten Zeitraum.',
        'no_projects' => [
            'title' => 'Noch keine Projekte',
            'description' => 'Eine Übersicht braucht etwas, das meldet. Legen Sie ein Projekt an und schließen Sie es an.',
            'action' => 'Zu den Projekten',
        ],
        'help' => [
            'panels' => 'Jede Kachel holt ihre Zahlen selbst — die Seite steht sofort und füllt sich.',
            'filter' => 'Die Filterleiste oben gilt für alle Kacheln. Ihre Auswahl steht in der Adresszeile — der Link zeigt beim Empfänger dieselbe Ansicht.',
            'links' => 'Jede Zahl führt in die Ansicht, aus der sie stammt — mit demselben Zeitraum.',
        ],
        'errors' => [
            'title' => 'Fehlerverlauf',
            'description' => 'Gemeldete Fehler über die gewählten Projekte.',
            'metric' => 'Fehler',
        ],
        'transactions' => [
            'title' => 'Transaktionsverlauf',
            'description' => 'Gemessene Aufrufe über die gewählten Projekte. Antwortzeiten stehen je Projekt — über mehrere hinweg ist ein Perzentil keine Zahl.',
            'metric' => 'Aufrufe',
        ],
        'projects' => [
            'title' => 'Top-Projekte',
            'description' => 'Wo im Zeitraum die meisten Fehler auftraten.',
            'metric' => 'Fehler',
        ],
        'alerts' => [
            'title' => 'Offene Alarme',
            'description' => 'Alarme, die gerade nicht in Ordnung sind — unabhängig vom gewählten Zeitraum.',
            'value' => 'Zuletzt gemessen',
            'empty' => 'Kein Alarm ist ausgelöst.',
        ],
        'quota' => [
            'title' => 'Kontingent',
            'description' => 'Verbrauch dieses Monats gegen die Grenzen der Organisation.',
            'unlimited' => 'Ohne Grenze',
            'of' => 'von :limit',
        ],
    ],

    'project' => [
        'title' => 'Übersicht: :name',
        'description' => 'Zustand und offene Punkte dieses Projekts.',
        'settings' => 'Einstellungen',
        'alerts' => 'Alarm-Übersicht',
        'issues_link' => 'Fehlerliste',
        'errors' => [
            'title' => 'Fehlerverlauf',
            'description' => 'Gemeldete Fehler dieses Projekts.',
            'metric' => 'Fehler',
        ],
        'issues_panel' => [
            'title' => 'Neueste Fehler',
            'description' => 'Offene Einträge, die im Zeitraum aufgetreten sind.',
        ],
        'issues' => [
            'times_seen' => 'Häufigkeit',
            'users_seen' => 'Betroffene',
        ],
        'releases' => [
            'title' => 'Release-Gesundheit',
            'description' => 'Die letzten Auslieferungen im gewählten Zeitraum.',
            'crash_free' => 'Absturzfrei',
            'adoption' => 'Verbreitung',
        ],
        'ownership' => [
            'title' => 'Zuständigkeiten',
            'description' => 'Wer für dieses Projekt zuständig ist — unabhängig vom Zeitraum.',
            'teams' => 'Teams',
            'rules' => 'Aktive Regeln',
            'empty' => 'Diesem Projekt ist kein Team zugeordnet.',
        ],
    ],

    'team' => [
        'title' => 'Team: :name',
        'description' => 'Was auf dieses Team wartet.',
        'settings' => 'Team verwalten',
        'projects' => [
            'title' => 'Projekte des Teams',
            'description' => 'Die Projekte dieses Teams mit ihren Fehlern im Zeitraum.',
            'metric' => 'Fehler',
            'empty' => 'Diesem Team ist kein Projekt zugeordnet.',
        ],
        'review' => [
            'title' => 'Ungeprüfte Fehler',
            'description' => 'Neue Einträge, die noch niemand angesehen hat.',
        ],
        'assignments' => [
            'title' => 'Zuweisungen',
            'description' => 'Offene Fehler, die dem Team oder einem seiner Mitglieder zugewiesen sind.',
        ],
        'issues' => [
            'times_seen' => 'Häufigkeit',
        ],
    ],

];
