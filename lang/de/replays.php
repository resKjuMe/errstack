<?php

// Sitzungs-Aufzeichnungen (resources/js/shell/pages/replays, App\Http\Controllers\ReplayController).
return [

    'title' => 'Aufzeichnungen',
    'detail_title' => 'Aufzeichnung',

    'help' => [
        'purpose' => 'Eine Aufzeichnung zeigt, was der Nutzer vor dem Fehler getan hat — Klicks, Eingaben, Seitenwechsel. Sie beantwortet die Frage, die weder Stacktrace noch Breadcrumbs beantworten: wie ist er überhaupt dorthin gekommen?',
        'entry' => 'Der übliche Weg hierher führt von einem Fehler und nicht über diese Liste: die Fehlerseite verlinkt die Aufzeichnungen, in denen genau diese Meldung passiert ist.',
        'masking' => 'Maskiert wird im Browser, bevor etwas gesendet wird — Texte und Eingabefelder sind standardmäßig ersetzt. Was hier ankommt, ist bereits maskiert; nachträglich ließe sich das nicht herstellen.',
        'retention' => 'Aufzeichnungen werden getrennt von den Ereignisdaten gespeichert und nach einer eigenen, kürzeren Frist gelöscht. Die Frist steht in den Datenschutz-Einstellungen des Projekts.',
        'sampling' => 'Aufgezeichnet wird nur ein Teil der Sitzungen — das SDK hat dafür eine eigene Quote. Dass es zu einem Fehler keine Aufzeichnung gibt, ist deshalb der Normalfall und kein Mangel.',
    ],

    'list' => [
        'heading' => 'Aufgezeichnete Sitzungen',
        'columns' => [
            'started' => 'Beginn',
            'duration' => 'Dauer',
            'user' => 'Betroffene Person',
            'url' => 'Einstiegsseite',
            'errors' => 'Fehler',
            'browser' => 'Browser',
            'environment' => 'Umgebung',
        ],
        'open' => 'Aufzeichnung abspielen',
        'only_errors' => 'Nur Sitzungen mit Fehlern',
        'all_sessions' => 'Alle Sitzungen',
        'limit' => 'Gezeigt werden die :limit neuesten von :total Aufzeichnungen des Zeitraums.',
        'total' => ':count Aufzeichnungen im Zeitraum.',
        'empty' => 'Im gewählten Zeitraum wurden für diese Projekte keine Sitzungen aufgezeichnet.',
        'empty_errors' => 'Im gewählten Zeitraum gibt es keine aufgezeichnete Sitzung mit einem Fehler.',
        'empty_hint' => 'Aufzeichnungen kommen aus der Replay-Integration des Browser-SDK. Solange sie nicht eingerichtet ist, bleibt diese Liste leer, auch wenn Fehler ankommen — die Einrichtungsanleitung des Projekts zeigt den nötigen Aufruf.',
        'ongoing' => 'läuft noch',
        'anonymous' => 'Ohne Kennung',
        'more_urls' => '+:count weitere Seiten',
    ],

    'player' => [
        'heading' => 'Abspielen',
        'play' => 'Abspielen',
        'pause' => 'Pause',
        'restart' => 'Von vorn',
        'speed' => 'Geschwindigkeit',
        'skip_inactive' => 'Pausen überspringen',
        'loading' => 'Aufzeichnung wird geladen …',
        'loading_hint' => 'Die Bilddaten kommen getrennt von der Seite; bei einer langen Sitzung dauert das einen Moment.',
        'failed' => 'Die Aufzeichnung ließ sich nicht laden.',
        'failed_hint' => 'Möglicherweise wurde sie inzwischen nach Ablauf der Aufbewahrungsfrist gelöscht. Laden Sie die Seite neu; bleibt es dabei, steht in der Liste, was es sonst noch gibt.',
        'empty' => 'Zu dieser Sitzung sind keine Bilddaten vorhanden.',
        'position' => ':position von :duration',
    ],

    'timeline' => [
        'heading' => 'Zeitleiste',
        'hint' => 'Ein Klick auf einen Eintrag springt an die Stelle im Film.',
        'jump' => 'An diese Stelle springen',
        'truncated' => 'Gekappt: :count weitere Einträge werden nicht gezeigt.',
    ],

    'tracks' => [
        'errors' => 'Fehler',
        'breadcrumbs' => 'Spuren',
        'console' => 'Konsole',
        'network' => 'Netzwerk',
        'empty' => 'Für diese Spur wurde nichts aufgezeichnet.',
        'network_columns' => [
            'time' => 'Zeitpunkt',
            'method' => 'Methode',
            'description' => 'Adresse',
            'status' => 'Status',
            'size' => 'Größe',
            'duration' => 'Dauer',
        ],
    ],

    'meta' => [
        'heading' => 'Sitzung',
        'user' => 'Betroffene Person',
        'browser' => 'Browser',
        'os' => 'Betriebssystem',
        'device' => 'Gerät',
        'environment' => 'Umgebung',
        'release' => 'Version',
        'sdk' => 'SDK',
        'started' => 'Beginn',
        'duration' => 'Dauer',
        'segments' => 'Abschnitte',
        'events' => 'Aufgezeichnete Ereignisse',
        'size' => 'Speicherbedarf',
        'urls' => 'Besuchte Seiten',
        'replay_id' => 'Kennung',
        'unknown' => 'Unbekannt',
    ],

    'masking' => [
        'masked' => 'Maskiert',
        'masked_hint' => 'Das SDK hat Texte und Eingaben vor dem Senden ersetzt.',
        'unmasked' => 'Nicht maskiert',
        'unmasked_hint' => 'Dieses SDK hat die Maskierung abgeschaltet. Die Aufzeichnung kann Eingaben und Texte im Klartext enthalten — prüfen Sie die Einrichtung der überwachten Anwendung.',
    ],

    'issue' => [
        'heading' => 'Sitzungs-Aufzeichnungen',
        'hint' => 'Was der Nutzer vor dieser Meldung getan hat.',
        'open' => 'Aufzeichnung ansehen',
    ],

];
