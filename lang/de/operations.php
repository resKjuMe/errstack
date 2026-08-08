<?php

return [

    'title' => 'Betrieb',

    'help' => 'Der Zustand dieser Installation: was noch antwortet, ob die Verarbeitung mitkommt und was liegengeblieben ist. Die Seite fragt bei jedem Aufruf frisch nach — sie hält keine Werte vor.',

    'health' => [
        'title' => 'Zustand',
        'description' => 'Geprüft wird schreibend und lesend, nicht nur die Verbindung. Dieselben Prüfungen beantworten :route für Ladeverteiler und fremde Überwachung.',
        'checks' => [
            'database' => 'Datenbank',
            'cache' => 'Zwischenspeicher',
            'queue' => 'Warteschlange',
            'storage' => 'Dateiablage',
        ],
        'overall_ok' => 'Alles antwortet',
        'overall_failed' => 'Ein Bestandteil antwortet nicht',
        'state' => [
            'ok' => 'in Ordnung',
            'failed' => 'gestört',
        ],
    ],

    'backlog' => [
        'title' => 'Rückstand',
        'description' => 'Angenommene, aber noch nicht ausgewertete Meldungen. Die Menge allein sagt wenig — entscheidend ist, wie lange die älteste schon wartet.',
        'pending' => 'Wartende Meldungen',
        'oldest' => 'Älteste wartet seit',
        'threshold' => 'Schwelle: :pending Meldungen oder :age Sekunden Alter',
        'ok' => 'Im Rahmen',
        'breaching' => 'Über der Schwelle seit :since',
        'breaching_unknown' => 'Über der Schwelle',
        'reasons' => [
            'pending' => 'zu viele wartende Meldungen',
            'age' => 'älteste Meldung zu alt',
        ],
        'none' => 'nichts wartet',
        'seconds' => ':value s',
    ],

    'durations' => [
        'title' => 'Rechenzeit der Verarbeitung',
        'description' => 'Wie lange die Kette je Meldung rechnet, über die letzten :count Durchläufe.',
        'empty' => 'Noch keine ausgewertete Meldung.',
        'avg' => 'Mittel',
        'p95' => '95. Perzentil',
        'max' => 'Längste',
    ],

    'latency' => [
        'title' => 'Von der Annahme bis zur Sichtbarkeit',
        'description' => 'Die Dauer, die ein Nutzer merkt — Wartezeit in der Warteschlange eingeschlossen. Läuft sie auseinander, während die Rechenzeit gleich bleibt, fehlen Arbeiter.',
    ],

    'queues' => [
        'title' => 'Warteschlangen',
        'description' => 'Jobs, die auf ihre Abholung warten. Stauen sich alle gleichzeitig, läuft kein Arbeiter mehr.',
        'unknown' => 'nicht zählbar',
    ],

    'states' => [
        'title' => 'Meldungen nach Zustand',
        'description' => 'Alle je angenommenen Meldungen, nach dem Ergebnis ihrer Verarbeitung.',
    ],

    'failed_jobs' => [
        'title' => 'Gescheiterte Jobs',
        'description' => 'Jobs, die alle Versuche verbraucht haben. Erneut gestartet werden sie mit derselben Nutzlast wie beim ersten Mal.',
        'empty' => 'Nichts liegengeblieben.',
        'count' => ':count Einträge, die jüngsten :shown werden gezeigt',
        'columns' => [
            'name' => 'Job',
            'queue' => 'Warteschlange',
            'failed_at' => 'Gescheitert am',
            'exception' => 'Grund',
        ],
        'retry' => 'Erneut starten',
        'retry_all' => 'Alle erneut starten',
        'forget' => 'Verwerfen',
        'retried_one' => 'Der Job ist wieder eingereiht.',
        'retried_all' => ':count Job(s) sind wieder eingereiht.',
        'forgotten' => 'Der Eintrag ist verworfen.',
        'gone' => 'Diesen Eintrag gibt es nicht mehr — jemand war schneller.',
    ],

    'failed_payloads' => [
        'title' => 'Gescheiterte Meldungen',
        'description' => 'Etwas anderes als gescheiterte Jobs: hier liegen die Rohdaten noch. Nach einem reparierten Schritt der Kette lassen sie sich erneut durchlaufen, auch wenn es längst keinen Job mehr gibt.',
        'count' => ':count Meldung(en) warten auf einen erneuten Durchlauf.',
        'empty' => 'Keine gescheiterte Meldung.',
        'retry' => 'Erneut einreihen',
        'retried' => ':count Meldung(en) sind erneut eingereiht.',
        'limit_hint' => 'Höchstens :limit auf einmal — mehr würde den Rückstand zurückbringen, den man gerade abbaut.',
    ],

];
