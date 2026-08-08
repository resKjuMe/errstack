<?php

// Die Leistungsprobleme (app/Http/Controllers/PerformanceIssueController,
// app/Http/Controllers/ProjectPerformanceController,
// resources/js/shell/pages/performance).
return [

    'title' => 'Leistungsprobleme',
    'help' => 'Muster, die die Erkennung in gespeicherten Abläufen gefunden hat — '
        .'N+1-Abfragen, doppelte Abfragen, langsame Aufrufe, blockierende Ressourcen. '
        .'Ein Eintrag steht für ein Muster an einer Stelle, nicht für einen einzelnen '
        .'Aufruf: gezählt wird, wie oft es aufgetreten ist, und aufsummiert, wie viel '
        .'Zeit es gekostet hat. Fehler stehen getrennt davon in der Fehlerliste.',

    'list' => [
        'untitled' => 'Ohne Titel',
        'empty' => 'Keine Leistungsprobleme im gewählten Zeitraum.',
        'empty_hint' => 'Die Erkennung läuft im Hintergrund über gespeicherte Abläufe. '
            .'Sobald ein Muster eine Schwelle überschreitet, steht es hier.',
        'count' => ':count Leistungsprobleme',
        'first_seen' => 'Zuerst: :value',
        'per_event' => ':value je Vorfall',
    ],

    'columns' => [
        'problem' => 'Problem',
        'trend' => 'Verlauf',
        'time_lost' => 'Verlorene Zeit',
        'events' => 'Häufigkeit',
        'users' => 'Nutzer',
        'last_seen' => 'Zuletzt',
    ],

    // Die Werte von App\Enums\PerformanceIssueSort.
    'sort' => [
        'time_lost' => 'Verlorene Zeit',
        'times_seen' => 'Häufigkeit',
        'last_seen' => 'Zuletzt aufgetreten',
        'first_seen' => 'Zuerst aufgetreten',
    ],

    'filter' => [
        'sort' => 'Sortierung',
        'status' => 'Zustand',
        'problem' => 'Muster',
        'any_problem' => 'Alle Muster',
        'environment_ignored' => 'Die Zähler eines Eintrags gelten über alle Umgebungen '
            .'hinweg — die gewählte Umgebung wirkt sich auf diese Liste nicht aus. '
            .'Sie bestimmt aber, welche Auslieferungen im Verlauf markiert werden.',
    ],

    'detail' => [
        'back' => 'Zurück zur Liste',
        'examples' => 'Beispiele',
        'examples_hint' => 'Die teuersten Vorfälle dieses Musters, mit den betroffenen '
            .'Schritten des Ablaufs.',
        'no_examples' => 'Zu diesem Eintrag liegen keine Belege mehr vor — die Abläufe '
            .'sind aus der Aufbewahrung gefallen.',
        'trace' => 'Ablauf',
        'transaction' => 'Transaktion',
        'occurred_at' => 'Aufgetreten',
        'time_lost' => 'Verlorene Zeit',
        'spans' => 'Betroffene Schritte',
        'span_count' => ':count Schritte',
        'total_time_lost' => 'Verlorene Zeit insgesamt',
        'time_lost_per_event' => 'Je Vorfall',
        'times_seen' => 'Häufigkeit',
        'users_seen' => 'Betroffene Nutzer',
        'first_seen' => 'Zuerst aufgetreten',
        'last_seen' => 'Zuletzt aufgetreten',
    ],

    'evidence' => [
        'repeats' => 'Wiederholungen',
        'total_us' => 'Gesamtdauer',
        'longest_us' => 'Längste davon',
        'duration_us' => 'Dauer',
        'threshold_us' => 'Schwelle',
        'blocking_us' => 'Blockierend',
        'source_description' => 'Auslösende Abfrage',
        'encoded_bytes' => 'Übertragen',
        'decoded_bytes' => 'Entpackt',
        'uncompressed' => 'Unkomprimiert',
        'misses' => 'Fehlgriffe',
        'hits' => 'Treffer',
        'method' => 'Verb',
        'status' => 'Status',
        'resource_op' => 'Art',
        'yes' => 'Ja',
        'no' => 'Nein',
    ],

    'settings' => [
        'title' => 'Leistungserkennung',
        'help' => 'Ab wann ein Muster als Problem gilt. Die Erkennung läuft im '
            .'Hintergrund über die gespeicherten Abläufe dieses Projekts; die Schwellen '
            .'entscheiden, was davon in der Liste landet. Ein abgeschaltetes Muster '
            .'wird nicht mehr gesucht — bereits erkannte Einträge bleiben stehen.',
        'enabled' => 'Erkennung aktiv',
        'default_hint' => 'Vorgabe: :value',
        'changed_hint' => 'Abweichend von der Vorgabe (:value)',
        'save' => 'Schwellen speichern',
        'issues_link' => 'Erkannte Leistungsprobleme ansehen',
        'read_only' => 'Zum Ändern der Schwellen fehlt die Berechtigung.',
    ],

    'flash' => [
        'settings_updated' => 'Die Schwellen der Leistungserkennung wurden gespeichert.',
    ],

];
