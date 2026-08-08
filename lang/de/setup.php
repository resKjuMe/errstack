<?php

// Der Einrichtungs-Assistent (O8): vom neuen Projekt zum ersten Fehler. Der
// Beispielcode selbst steht nicht hier, sondern in App\Support\Setup\SetupGuide
// — er wird nicht übersetzt.
return [
    'title' => 'Einrichtung: :project',
    'help' => 'Wählen Sie die Technik Ihrer Anwendung, übernehmen Sie das Beispiel mit Ihrer DSN und lösen Sie einen Testfehler aus. Sobald die erste Meldung hier ankommt, meldet sich diese Seite von selbst — ein Neuladen ist nicht nötig.',
    'to_settings' => 'Einstellungen',
    'copy' => 'Kopieren',
    'copied' => 'Kopiert',

    // Der Weg zurück, aus den Projekt-Einstellungen.
    'card' => [
        'title' => 'Einrichtung',
        'description' => 'Anleitung und Beispielcode für den Anschluss einer Anwendung — jederzeit erneut aufrufbar.',
        'open' => 'Assistent öffnen',
    ],

    'platform' => [
        'title' => '1. Technik wählen',
        'description' => 'Die Auswahl bestimmt nur das Beispiel. Ein Projekt darf Meldungen aus mehreren Anwendungen bekommen — die Plattform in den Einstellungen ändert sich dadurch nicht.',
    ],

    'code' => [
        'title' => '2. SDK einbinden — :guide',
        'description' => 'Das offizielle SDK :package, unverändert. Die DSN ist bereits eingesetzt.',
        'dsn' => 'DSN (Schlüssel „:key")',
        'install' => 'Installieren',
        'configure' => 'Einstellen',
        'verify' => 'Testfehler auslösen',
        'official' => 'Errstack nimmt Meldungen der Original-SDKs entgegen; nichts daran wird angepasst.',
        'docs' => 'Anleitung zu :package',
    ],

    'waiting' => [
        'title' => '3. Warten auf das erste Ereignis',
        'description' => 'Diese Seite bemerkt die erste Meldung von selbst.',
        'hint' => 'Noch nichts angekommen. Lösen Sie den Testfehler aus Schritt 3 aus — die Anzeige wechselt von allein.',
        'spinner' => 'Wartet auf das erste Ereignis',
    ],

    'received' => [
        'title' => 'Ereignis empfangen',
        'description' => 'Die erste Meldung dieses Projekts ist am :time angekommen.',
        'description_sdk' => 'Die erste Meldung dieses Projekts ist am :time angekommen, gesendet von :sdk.',
        'open' => 'Fehler ansehen',
        'processing' => 'Die Meldung wird gerade ausgewertet. Der Fehler erscheint gleich hier — und in der Fehlerliste.',
        'to_issues' => 'Zur Fehlerliste',
    ],

    'help_section' => [
        'open' => 'Es kommt nichts an?',
        'title' => 'Es kommt nichts an',
        'description' => 'Die häufigsten Ursachen, wenn nach dem Testfehler nichts erscheint.',
        'to_keys' => 'Client-Schlüssel prüfen',
        'to_docs' => 'Anleitung zu :package',
        'causes' => [
            'dsn' => 'Die DSN stimmt nicht überein — sie muss zeichengleich aus Schritt 2 stammen. Ein zweites Konfigurationsfeld (Umgebungsvariable, .env, Build-Einstellung) überschreibt sie gern still.',
            'reachable' => 'Die Anwendung erreicht diese Installation nicht: Firewall, Proxy oder ein Adressname, den nur Ihr Browser auflöst und der Server der Anwendung nicht.',
            'flush' => 'Der Prozess war schneller fertig als das Senden. Kurzlebige Skripte, Konsolenbefehle und Lambda-Funktionen brauchen ein abschließendes Leeren des Puffers (`flush`).',
            'sample_rate' => 'Eine Stichprobe hat die Meldung nicht gezogen. Für den Test gehören Fehlerrate und `traces_sample_rate` auf 1.0; auch ein `before_send`, das null zurückgibt, verwirft still.',
            'key_disabled' => 'Der verwendete Schlüssel ist abgeschaltet oder wurde neu gezogen — dann wird jede Meldung abgewiesen, die noch den alten trägt.',
            'filters' => 'Ein Eingangsfilter oder eine Datenschutz-Regel des Projekts hat die Meldung aussortiert. Was aussortiert wurde, steht oben.',
        ],
        'discards' => [
            'title' => 'Es kam etwas an — behalten wurde es nicht',
            'description' => 'In den letzten 24 Stunden wurden Meldungen dieses Projekts verworfen. Die Verbindung steht also; gesucht wird der Grund.',
            'entry' => ':count × :reason (:origin)',
            'origin' => [
                'server' => 'von Errstack abgewiesen',
                'client' => 'vom SDK selbst verworfen',
            ],
        ],
    ],

    'no_key' => [
        'title' => 'Kein aktiver Schlüssel',
        'description' => 'Dieses Projekt hat keinen aktiven Client-Schlüssel und damit keine DSN, an die gemeldet werden könnte.',
        'to_keys' => 'Schlüssel verwalten',
    ],

    'guides' => [
        'php-laravel' => ['label' => 'Laravel'],
        'php' => ['label' => 'PHP'],
        'javascript-browser' => ['label' => 'JavaScript (Browser)'],
        'javascript-react' => ['label' => 'React'],
        'node' => ['label' => 'Node.js'],
        'python' => ['label' => 'Python'],
    ],
];
