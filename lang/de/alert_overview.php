<?php

// Alarm-Übersicht und -Verlauf (app/Http/Controllers/AlertOverviewController,
// app/Http/Controllers/AlertSnoozeController, app/Support/Alerts,
// resources/js/shell/pages/projects/AlertOverview.jsx und AlertDetail.jsx).
return [

    'title' => 'Alarm-Übersicht — :project',
    'detail_title' => ':alert — :project',
    'help' => 'Alle Alarme dieses Projekts an einer Stelle: Schwellwert-Alarme auf '
        .'Kennzahlen und Alarm-Regeln für Fehler, mit Zustand, letzter Auslösung und '
        .'Verlauf. Eingerichtet werden sie weiterhin auf ihren eigenen Seiten — hier '
        .'wird nachgesehen und bei Bedarf befristet Ruhe gegeben.',

    'intro' => [
        'title' => 'Was diese Seite beantwortet',
        'description' => 'Nach einer Benachrichtigung ist die Frage selten „welche Kennzahl?", '
            .'sondern „welche Regel war das, und wie oft passiert das?". Deshalb stehen beide '
            .'Arten in einer Liste, sortiert nach der letzten Auslösung.',
        'snooze_hint' => 'Eine Stummschaltung verhindert die Benachrichtigung, nicht die '
            .'Auswertung: Zustandswechsel und Auslösungen stehen weiterhin im Verlauf. Wer '
            .'eine Nacht Ruhe haben will, verliert damit nicht die Auskunft, was in dieser '
            .'Nacht los war.',
    ],

    'kinds' => [
        'metric' => 'Schwellwert-Alarm',
        'issue' => 'Fehler-Regel',
    ],

    'states' => [
        'all' => 'Alle Zustände',
        'fired' => 'Ausgelöst',
        'warning' => 'Warnung',
        'critical' => 'Kritisch',
        'resolved' => 'Entwarnt',
        'armed' => 'Scharf',
        'off' => 'Abgeschaltet',
    ],

    'scopes' => [
        'everyone' => 'Für alle',
        'personal' => 'Nur für mich',
    ],

    'durations' => [
        60 => '1 Stunde',
        120 => '2 Stunden',
        240 => '4 Stunden',
        480 => '8 Stunden',
        1440 => '24 Stunden',
        4320 => '3 Tage',
        10080 => '7 Tage',
    ],

    'filter' => [
        'period' => 'Zeitraum',
        'state' => 'Zustand',
        'range' => 'Verlauf von :from bis :to',
    ],

    'list' => [
        'title' => 'Regeln und Alarme',
        'empty' => 'Für dieses Projekt ist noch kein Alarm und keine Alarm-Regel eingerichtet.',
        'frequency' => 'Höchstens eine Meldung je Fehler in :minutes Minuten',
        'last' => 'Zuletzt ausgelöst',
        'never' => 'noch nie',
        'count' => 'Im Zeitraum',
        'count_value' => ':count ×',
        'state_since' => 'Zustand seit',
        'detail' => 'Verlauf ansehen',
        'config' => 'Einstellungen',
    ],

    'snooze' => [
        'title' => 'Stummschalten',
        'duration' => 'Dauer',
        'scope' => 'Geltungsbereich',
        'submit' => 'Stummschalten',
        'lift' => 'Stummschaltung aufheben',
        'everyone_active' => 'Für alle stumm bis :until',
        'everyone_active_by' => 'Für alle stumm bis :until — gesetzt von :by',
        'mine_active' => 'Für mich stumm bis :until',
        'no_personal_effect' => 'Diese Regel meldet nur an gemeinsame Kanäle. Eine Stummschaltung '
            .'nur für mich bliebe dort wirkungslos — still wird sie erst für alle.',
        'manage_only' => 'Für alle stummschalten darf nur die Verwaltung.',
    ],

    'history' => [
        'title' => 'Verlauf',
        'empty' => 'Im gewählten Zeitraum ist nichts passiert.',
        'deliveries' => ':count Zustellungen',
        'no_deliveries' => 'keine Zustellung',
        'truncated' => 'Es werden die jüngsten :limit Einträge gezeigt.',
    ],

    'chart' => [
        'title' => 'Auslösungen je Zeitabschnitt',
        'label' => 'Auslösungen im gewählten Zeitraum',
        'total' => ':count Einträge im Zeitraum',
        'truncated' => 'Gezählt wurden die jüngsten :limit Einträge — es waren mehr.',
        'empty' => 'Im gewählten Zeitraum gibt es nichts zu zeigen.',
    ],

    'detail' => [
        'back' => 'Zurück zur Übersicht',
        'facts' => 'Einstellung',
        'actions' => 'Ausgelöste Aktionen',
        'metric_chart' => 'Verlauf der Kennzahl',
        'deliveries' => 'Zustellungen',
        'deliveries_empty' => 'Für diese Regel ist noch nichts an einen Kanal hinausgegangen.',
        'deliveries_hint' => 'Aufgeführt sind die Zustellungen an die gemeinsamen Kanäle. '
            .'Persönliche Benachrichtigungen gehen als Mail an den Einzelnen und werden nicht '
            .'protokolliert; Zustellungen von vor der Einführung dieser Ansicht fehlen.',
        'delivery_attempts' => ':count Versuche',
        'delivered_at' => 'Zugestellt :at',
    ],

    'facts' => [
        'window' => 'Zeitfenster',
        'conditions' => 'Anlässe',
        'filters' => 'Einschränkungen',
        'frequency' => 'Häufigkeitsbegrenzung',
        'none' => 'keine',
    ],

    'actions' => [
        'all_channels' => 'An alle aktiven Kanäle der Organisation',
    ],

    'flash' => [
        'snoozed' => '„:name" ist stumm bis :until.',
        'unsnoozed' => 'Die Stummschaltung von „:name" ist aufgehoben.',
    ],

];
