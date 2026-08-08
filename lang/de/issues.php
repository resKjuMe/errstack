<?php

// Die Fehlerliste (app/Http/Controllers/IssueController,
// resources/js/shell/pages/issues).
return [

    'title' => 'Fehler',
    'help' => 'Alle Fehler der gewählten Projekte, mit Häufigkeit, betroffenen '
        .'Nutzern und Verlauf. Ein Eintrag steht für einen Fehler, nicht für eine '
        .'Meldung: gezählt wird, wie oft er aufgetreten ist. Der Zeitraum der '
        .'Filterleiste bestimmt, welche Fehler erscheinen — jeder, der darin '
        .'aufgetreten ist.',

    'list' => [
        'untitled' => 'Ohne Titel',
        'empty' => 'Keine Fehler im gewählten Zeitraum.',
        'empty_hint' => 'Sobald eine Meldung eingeht, steht sie hier.',
        'count' => ':count Fehler',
        'first_seen' => 'Zuerst: :value',
    ],

    'columns' => [
        'issue' => 'Fehler',
        'trend' => 'Verlauf',
        'events' => 'Häufigkeit',
        'users' => 'Nutzer',
        'last_seen' => 'Zuletzt',
    ],

    // Die Werte von App\Enums\IssueSort.
    'sort' => [
        'last_seen' => 'Zuletzt aufgetreten',
        'first_seen' => 'Zuerst aufgetreten',
        'times_seen' => 'Häufigkeit',
        'priority' => 'Dringlichkeit',
    ],

    'filter' => [
        'sort' => 'Sortierung',
        'status' => 'Zustand',
        'any_status' => 'Alle',
        'search' => 'Suche',
        'search_placeholder' => 'z. B. is:unresolved browser:Chrome timesSeen:>100',
        'search_hint' => 'Feld:Wert, mehrere Begriffe sind ein Und. `!` verneint, '
            .'`or` und Klammern verknüpfen, `*` steht für beliebige Zeichen.',
        'search_error' => 'Der Suchausdruck wurde nicht verstanden: :message '
            .'Gezeigt wird deshalb die ungefilterte Liste.',
        'search_error_at' => 'an dieser Stelle: :excerpt',
        'search_unavailable' => 'Noch nicht auswertbar: :terms. Diese Begriffe gehören zur '
            .'Suchsprache, aber die Daten dazu kommen erst mit einer späteren Aufgabe — '
            .'sie schränken die Liste nicht ein.',
        'search_suggestions' => 'Vorschläge',
    ],

    // Die betroffenen Versionen an einer Zeile der Liste.
    'release' => [
        'first' => 'Zuerst in',
        'last' => 'Zuletzt in',
        'only' => 'In',
    ],

    'trend' => [
        'label' => 'Verlauf, :period',
    ],

    'selection' => [
        'row' => 'Fehler auswählen',
        'page' => 'Alle auf dieser Seite auswählen',
        'selected' => ':count ausgewählt',
        'select_all' => 'Alle :count Fehler auswählen',
        'all_selected' => 'Alle :count Fehler der Auswahl sind ausgewählt.',
        'clear' => 'Auswahl aufheben',
        'no_actions' => 'Sammelaktionen kommen mit einer der nächsten Aufgaben.',
    ],

    'live' => [
        'new_one' => 'Ein neuer Fehler',
        'new_many' => ':count neue Fehler',
        'show' => 'Anzeigen',
    ],

    'environment_ignored' => 'Die gewählte Umgebung schränkt diese Liste nicht ein: '
        .'ein Fehler wird über alle Umgebungen hinweg gezählt. Wer nur die Fehler '
        .'einer Umgebung sehen will, sucht nach environment:production.',

    // Die Detailseite (app/Http/Controllers/IssueDetailController,
    // resources/js/shell/pages/issues/Show.jsx und issues/detail/*).
    'detail' => [

        'help' => 'Diese Seite zeigt eine einzelne Meldung dieses Fehlers mit allem, '
            .'was zur Diagnose gehört: Stacktrace, die letzten Schritte davor und '
            .'den technischen Kontext. Häufigkeit, betroffene Nutzer und die beiden '
            .'Zeitpunkte gelten dagegen für den Fehler insgesamt. Über die '
            .'Schaltflächen oben wechselt man zwischen den Meldungen.',

        'no_event' => 'Zu diesem Fehler liegt keine Meldung mehr vor.',
        'no_event_hint' => 'Die Zähler bleiben stehen; die Einzelmeldungen können '
            .'aufgeräumt worden sein.',

        'nav' => [
            'label' => 'Meldung',
            'newest' => 'Neueste',
            'newer' => 'Neuere',
            'older' => 'Ältere',
            'oldest' => 'Älteste',
        ],

        'header' => [
            'times_seen' => 'Häufigkeit',
            'users_seen' => 'Betroffene',
            'first_seen' => 'Zuerst',
            'last_seen' => 'Zuletzt',
            'status' => 'Zustand',
            'priority' => 'Dringlichkeit',
        ],

        'meta' => [
            'title' => 'Meldung',
            'event_id' => 'Kennung',
            'occurred_at' => 'Aufgetreten',
            'received_at' => 'Eingegangen',
            'level' => 'Schweregrad',
            'platform' => 'Plattform',
            'environment' => 'Umgebung',
            'release' => 'Version',
            'dist' => 'Auslieferung',
            'server_name' => 'Server',
            'transaction' => 'Vorgang',
            'logger' => 'Protokollierer',
            'sdk' => 'SDK',
        ],

        'message' => [
            'title' => 'Meldungstext',
        ],

        'exception' => [
            'title' => 'Stacktrace',
            'caused_by' => 'Verursacht durch',
            'handled' => 'Aufgefangen',
            'unhandled' => 'Nicht aufgefangen',
            'mechanism' => 'Herkunft: :type',
        ],

        'frames' => [
            'empty' => 'Kein Stacktrace übermittelt.',
            'line' => 'Zeile :line',
            'column' => 'Spalte :column',
            'in_app' => 'Eigener Code',
            'unknown_file' => 'Unbekannte Stelle',
            'hidden_one' => 'Ein fremder Rahmen',
            'hidden_many' => ':count fremde Rahmen',
            'show' => 'Einblenden',
            'hide' => 'Ausblenden',
            'vars' => 'Variablen',
            'toggle' => 'Rahmen auf- und zuklappen',
        ],

        'breadcrumbs' => [
            'title' => 'Letzte Schritte',
            'description' => 'Was vor dem Fehler passiert ist — ältester Schritt zuerst.',
            'data' => 'Daten',
        ],

        'sections' => [
            'request' => 'Anfrage',
            'user' => 'Nutzer',
            'contexts' => 'Umgebung',
            'tags' => 'Merkmale',
            'extra' => 'Zusatzdaten',
            'modules' => 'Pakete',
        ],

        'notes' => [
            'title' => 'Diese Meldung wurde gekürzt.',
            'truncated' => 'Gekürzt: :paths',
            'invalid' => 'Verworfen: :paths',
        ],

        'raw' => [
            'title' => 'Rohdaten',
            'description' => 'Die ausgewertete Meldung und daneben das, was das SDK '
                .'geschickt hat.',
            'show' => 'Anzeigen',
            'hide' => 'Verbergen',
            'open' => 'In neuem Tab öffnen',
            'loading' => 'Wird geladen …',
            'failed' => 'Die Rohdaten konnten nicht geladen werden.',
        ],

    ],

];
