<?php

/*
|--------------------------------------------------------------------------
| Betrieb der eigenen Installation
|--------------------------------------------------------------------------
|
| Nicht zu verwechseln mit der Selbstüberwachung (config/selfmonitoring.php):
| die meldet die eigenen **Fehler** über den regulären Weg an eine
| Errstack-Installation. Hier geht es um den Zustand *dieser* Installation —
| läuft die Datenbank, kommt die Verarbeitung mit, liegen Jobs quer.
|
| Der Unterschied ist im Ernstfall der entscheidende: die Selbstüberwachung
| setzt voraus, dass die Anwendung noch so weit lebt, dass sie melden kann.
| Ein Rückstand, der wächst, ist kein Fehler — es meldet also niemand etwas,
| und genau deshalb muss jemand nachsehen. Die Zahlen dafür stehen hier.
|
*/

return [

    /*
    | `/health` — die Auskunft für Ladeverteiler, Container-Verwaltung und
    | jede fremde Überwachung.
    |
    | Ohne Anmeldung erreichbar, und deshalb ohne jede innere Auskunft: die
    | Antwort sagt je Bestandteil „geht" oder „geht nicht" und sonst nichts.
    | Keine Fehlermeldung, keine Verbindungsdaten, keine Zahlen — wer die
    | Adresse errät, soll daraus nicht ablesen können, welche Datenbank hier
    | steht und wie sie heißt.
    |
    | Absichtlich **nicht** Teil der Prüfung ist der Rückstand. Eine
    | Installation mit vollen Warteschlangen ist nicht kaputt, sie ist
    | beschäftigt; wer sie deswegen aus dem Ladeverteiler nimmt, nimmt ihr die
    | letzten Arbeiter weg. Der Rückstand gehört in die Kennzahlen und in die
    | Warnung an den Betreiber — beides weiter unten.
    */
    'health' => [

        // Ab welcher Antwortzeit eine Prüfung als gescheitert gilt, in
        // Millisekunden. Eine Datenbank, die für `select 1` drei Sekunden
        // braucht, ist für eine Fehlerannahme so gut wie weg.
        //
        // Das ist ausdrücklich **keine** Zeitschranke: die Prüfung wird nicht
        // abgebrochen, sondern hinterher an ihrer Dauer gemessen. Gegen ein
        // Hängen ohne Ende hilft in PHP nur die Zeitschranke des Treibers
        // selbst (`PDO::ATTR_TIMEOUT` und Verwandte), und die gehört in die
        // Verbindungseinstellung, nicht hierher.
        'slow_ms' => (int) env('ERRSTACK_HEALTH_SLOW_MS', 2000),

        // Die Ablage, in die zur Probe geschrieben wird. Dieselbe, in der die
        // Quellkarten und Anhänge liegen — eine andere zu prüfen hieße, die
        // falsche zu prüfen.
        'disk' => env('ERRSTACK_HEALTH_DISK', 'local'),

    ],

    /*
    | Der maschinenlesbare Kennzahl-Endpunkt (`/metrics`, Prometheus-Format).
    |
    | Ausgeliefert **aus**: er nennt Rückstände, Warteschlangenlängen und
    | Laufzeiten, und das ist mehr, als eine offene Adresse preisgeben darf.
    | Wer ihn einschaltet, stellt ihn entweder ins innere Netz oder hinterlegt
    | ein Token — beides ist eine bewusste Entscheidung und keine Vorgabe.
    */
    'metrics' => [

        'enabled' => (bool) env('ERRSTACK_METRICS_ENABLED', false),

        // Leer heißt: keine Prüfung. Dann muss das Netz die Adresse schützen.
        'token' => env('ERRSTACK_METRICS_TOKEN'),

        // Präfix aller Kennzahlnamen. Nur ändern, wenn mehrere Installationen
        // in dieselbe Zeitreihen-Datenbank schreiben und sich sonst
        // überschreiben würden.
        'prefix' => env('ERRSTACK_METRICS_PREFIX', 'errstack'),

    ],

    /*
    | Der Selbstschutz: die Schwellen, ab denen der Betreiber etwas hört.
    |
    | Zwei Größen, weil einzeln keine von beiden trägt. Die **Menge** allein
    | schlägt bei jedem Ansturm an, auch wenn er in zehn Sekunden abgearbeitet
    | ist. Das **Alter** allein bleibt still, solange eine einzige alte Meldung
    | quer liegt, während tausend frische auflaufen. Gewarnt wird, wenn eine
    | von beiden über die Schwelle geht — und zwar erst, wenn sie *dort bleibt*
    | ({@see grace_minutes}).
    */
    'backlog' => [

        // Wartende Meldungen, ab denen es auffällig wird.
        'max_pending' => (int) env('ERRSTACK_BACKLOG_MAX_PENDING', 1000),

        // Alter der ältesten wartenden Meldung in Sekunden. Fünf Minuten: was
        // länger liegt, ist in einer Fehlerverfolgung nicht mehr „gleich da".
        'max_age_seconds' => (int) env('ERRSTACK_BACKLOG_MAX_AGE_SECONDS', 300),

        // Wie lange die Schwelle überschritten sein muss, bevor gewarnt wird.
        // Ohne diese Frist meldet jeder Ansturm, der sich von selbst wieder
        // legt — und eine Warnung, die man wegklickt, ist keine mehr.
        'grace_minutes' => (int) env('ERRSTACK_BACKLOG_GRACE_MINUTES', 5),

        // Abstand, in dem eine anhaltende Lage erneut gemeldet wird. Ohne ihn
        // stünde dieselbe Warnung minütlich im Log.
        'repeat_minutes' => (int) env('ERRSTACK_BACKLOG_REPEAT_MINUTES', 60),

        // Log-Kanal für Warnung und Entwarnung. Der Standard-Stapel, damit die
        // Meldung dort landet, wo der Betreiber ohnehin nachsieht.
        'channel' => env('ERRSTACK_BACKLOG_LOG_CHANNEL'),

    ],

    /*
    | Wer die Betriebsansicht sehen darf.
    |
    | Eine Liste von E-Mail-Adressen, durch Komma getrennt. Sie steht in der
    | Umgebung und nicht in der Datenbank, weil sie eine Eigenschaft der
    | *Installation* ist und nicht der Daten darin: wer den Server betreibt,
    | kann die Umgebung ändern — wer nur ein Konto hat, nicht.
    |
    | Ist sie leer, sehen die Besitzer einer Organisation die Ansicht. Das ist
    | die brauchbare Vorgabe für die übliche Installation mit einer einzigen
    | Organisation; sobald es mehrere gibt, gehört die Liste gesetzt.
    */
    'operators' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ERRSTACK_OPERATORS', '')),
    ))),

];
