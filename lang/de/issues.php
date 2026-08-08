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
        'search_placeholder' => 'z. B. release:1.1.0 oder firstRelease:1.0.0',
        'search_unsupported' => 'Nicht ausgewertet: :terms. Die vollständige Suchsprache '
            .'kommt mit einer der nächsten Aufgaben; bis dahin wirken nur '
            .'release: und firstRelease:.',
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
        .'ein Fehler wird über alle Umgebungen hinweg gezählt.',

];
