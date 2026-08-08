<?php

// Profiling (resources/js/shell/pages/profiling, App\Http\Controllers\ProfilingController).
return [

    'title' => 'Profile',
    'detail_title' => 'Profil',

    'help' => [
        'purpose' => 'Ein Profil zeigt, welche Funktionen die Rechenzeit eines Aufrufs verbraucht haben — nicht wie lange er gedauert hat, sondern wo dabei gerechnet wurde.',
        'aggregate' => 'Ein einzelnes Profil kann den Ausreißer erwischt haben. Wählen Sie eine Transaktion, um alle ihre Profile übereinanderzulegen; erst dann zeigt sich das Muster.',
        'self_total' => 'Selbstzeit ist die Zeit in der Funktion selbst, Gesamtzeit die von ihr abwärts. Wer nur die Gesamtzeit ansieht, landet immer beim Einstiegspunkt — der ist per Definition der größte und sagt nichts.',
        'gap' => 'Die Rechenzeit ist fast immer kürzer als die Antwortzeit: Warten auf Datenbank, Netzwerk oder Sperren verbraucht keine. Steht in der Transaktion eine Sekunde und im Profil 40 ms, war es nicht der Code.',
        'sampling' => 'Gemessen wird durch Abtasten in festen Abständen. Wenige Stichproben sind ein Hinweis, keine Aussage — die Zahl steht deshalb an jeder Funktion.',
    ],

    'list' => [
        'heading' => 'Aufgenommene Profile',
        'columns' => [
            'transaction' => 'Transaktion',
            'started' => 'Zeitpunkt',
            'duration' => 'Rechenzeit',
            'samples' => 'Stichproben',
            'environment' => 'Umgebung',
            'release' => 'Version',
        ],
        'open' => 'Profil öffnen',
        'aggregate' => 'Alle Profile dieser Transaktion',
        'limit' => 'Gezeigt werden die :limit neuesten Profile des Zeitraums.',
        'empty' => 'Im gewählten Zeitraum wurden für diese Projekte keine Profile gemeldet.',
        'empty_hint' => 'Profile kommen aus dem Profiling des SDK der überwachten Anwendung und laufen dort mit einer eigenen Quote unter der Quote für Antwortzeiten. Solange das nicht eingerichtet ist, bleibt diese Liste leer, auch wenn Antwortzeiten ankommen.',
        'empty_transaction' => 'Für diese Transaktion wurden im gewählten Zeitraum keine Profile gemeldet.',
    ],

    'aggregate' => [
        'heading' => 'Zusammengefasst: :transaction',
        'hint' => 'Übereinandergelegt sind bis zu :limit Profile des Zeitraums.',
        'profiles' => ':count Profile · :samples Stichproben',
        'release' => 'Version',
        'all_releases' => 'Alle Versionen',
        'compare' => 'Vergleichen mit',
        'no_compare' => 'Kein Vergleich',
        'clear' => 'Auswahl aufheben',
        'empty' => 'Für diese Auswahl gibt es keine Profile.',
    ],

    'flamegraph' => [
        'heading' => 'Flamegraph',
        'search' => 'Funktion suchen',
        'search_placeholder' => 'Teil eines Funktions- oder Dateinamens',
        'matches' => ':count Treffer · :share der Rechenzeit',
        'no_matches' => 'Kein Treffer',
        'reset' => 'Ganzen Baum zeigen',
        'zoomed' => 'Ausschnitt: :function',
        'zoom' => 'Auf diesen Ast einzoomen',
        'collapse' => 'Ast einklappen',
        'expand' => 'Ast ausklappen',
        'collapsed' => ':count eingeklappt',
        'self' => 'Selbst',
        'total' => 'Gesamt',
        'samples' => 'Stichproben',
        'unknown_frame' => 'unbekannte Funktion',
        'empty' => 'Dieses Profil enthält keine auswertbaren Stichproben.',
        'incomplete' => ':dropped Wege beim Aufnehmen abgeschnitten, :pruned Äste unter einem Tausendstel der Zeit nicht gezeichnet.',
    ],

    'functions' => [
        'heading' => 'Funktionen',
        'columns' => [
            'function' => 'Funktion',
            'self' => 'Selbstzeit',
            'total' => 'Gesamtzeit',
            'samples' => 'Stichproben',
        ],
        'sort' => [
            'ascending' => 'Aufsteigend nach :column sortieren',
            'descending' => 'Absteigend nach :column sortieren',
        ],
        'in_app' => 'eigener Code',
        'limit' => 'Gezeigt werden die :limit Funktionen mit der größten Selbstzeit von :total insgesamt.',
        'empty' => 'Keine Funktion passt zu dieser Suche.',
    ],

    'comparison' => [
        'heading' => 'Vergleich: :baseline gegen :candidate',
        'hint' => 'Verglichen werden Anteile an der Rechenzeit, nicht Zeiten: die Zahl der Profile ist je Version verschieden, und absolute Werte wären damit nicht vergleichbar.',
        'columns' => [
            'function' => 'Funktion',
            'baseline' => ':release',
            'candidate' => ':release',
            'delta' => 'Veränderung',
        ],
        'empty' => 'Für eine der beiden Versionen gibt es im Zeitraum keine Profile.',
    ],

    'profile' => [
        'transaction' => 'Transaktion',
        'thread' => 'Ausführungsstrang',
        'platform' => 'Plattform',
        'started' => 'Zeitpunkt',
        'cpu' => 'Rechenzeit',
        'wall' => 'Antwortzeit',
        'samples' => 'Stichproben',
        'release' => 'Version',
        'environment' => 'Umgebung',
        'aggregate_link' => 'Alle Profile dieser Transaktion ansehen',
    ],

    'units' => [
        'microseconds' => 'µs',
        'milliseconds' => 'ms',
        'seconds' => 's',
    ],

];
