<?php

// Die Merkmal-Auswertung (app/Http/Controllers/TagController,
// app/Http/Controllers/IssueTagController, resources/js/shell/pages/tags,
// resources/js/shell/pages/issues/Tags.jsx).
return [

    'title' => 'Merkmale',
    'help' => 'Womit ein Fehler auftritt: Browser, Betriebssystem, Fassung, '
        .'Server und die Marken, die die Anwendung selbst setzt. Jeder Wert zeigt, '
        .'wie viele Meldungen ihn getragen haben und welcher Anteil das ist — ein '
        .'Fehler, der nur in einem Browser auftritt, ist ein anderer Fall als '
        .'einer, der alle trifft. Ein Klick auf einen Wert führt in die '
        .'Fehlerliste, eingeschränkt auf ihn.',

    'issue' => [
        'title' => 'Merkmale des Fehlers',
        'back' => 'Zurück zur Fehlerliste',
        'times_seen' => ':count Meldungen insgesamt',
    ],

    'project' => [
        'title' => 'Merkmale der Auswahl',
        'intro' => 'Alle Merkmale der gewählten Projekte, häufigste zuerst.',
    ],

    'detail' => [
        'back' => 'Zurück zur Übersicht',
        'values' => ':count verschiedene Werte',
        'total' => ':count Meldungen mit diesem Merkmal',
        'capped' => 'Von diesem Merkmal werden höchstens :limit verschiedene Werte '
            .'aufgehoben. Die übrigen zählen weiter in der Gesamtzahl mit, stehen '
            .'aber nicht einzeln in der Liste.',
    ],

    'list' => [
        'all_values' => 'Alle Werte',
        'rest' => 'Übrige',
        'filter' => 'Fehlerliste auf diesen Wert einschränken',
        'empty' => 'Noch keine Merkmale erfasst.',
        'empty_hint' => 'Merkmale entstehen beim Eingang der Meldungen. Sobald die '
            .'erste eingeht, steht sie hier.',
    ],

    'period_ignored' => 'Der gewählte Zeitraum schränkt diese Auswertung nicht ein: '
        .'Merkmale werden über die gesamte Lebensdauer eines Fehlers gezählt.',

    'link' => [
        'issue' => 'Merkmale',
        'overview' => 'Merkmale der Auswahl',
    ],

    // Geschriebene Namen der Merkmale, die aus den festen Feldern einer Meldung
    // stammen (App\Support\Tags\EventTags). Selbst gesetzte Marken behalten
    // ihren eigenen Namen.
    'keys' => [
        'level' => 'Schweregrad',
        'platform' => 'Plattform',
        'environment' => 'Umgebung',
        'release' => 'Fassung',
        'dist' => 'Auslieferung',
        'server_name' => 'Server',
        'transaction' => 'Vorgang',
        'logger' => 'Protokollierer',
        'url' => 'Adresse',
        'browser' => 'Browser',
        'browser_name' => 'Browser (ohne Fassung)',
        'os' => 'Betriebssystem',
        'os_name' => 'Betriebssystem (ohne Fassung)',
        'device' => 'Gerät',
        'device_family' => 'Gerätefamilie',
        'runtime' => 'Laufzeitumgebung',
        'runtime_name' => 'Laufzeitumgebung (ohne Fassung)',
        'sdk' => 'SDK',
    ],

];
