<?php

// Trace-Ansicht (resources/js/shell/pages/traces/Show.jsx,
// App\Http\Controllers\TraceController).
return [

    'title' => 'Spur',

    'help' => [
        'purpose' => 'Die Ansicht zeigt den gesamten Ablauf eines Aufrufs: alle beteiligten Dienste, ihre Einzelschritte und die Fehler, die dabei aufgetreten sind.',
        'waterfall' => 'Jeder Balken beginnt dort, wo der Schritt begann, und ist so breit wie seine Dauer. Eingerückt steht, was innerhalb eines anderen Schrittes lief — Lücken zwischen zwei Balken sind Wartezeit.',
        'gaps' => 'Fehlt ein übergeordneter Schritt, steht dort eine Lücke. Sie bedeutet nicht „nichts passiert", sondern „wir haben es nicht": ein Dienst ist nicht angebunden, seine Meldung ist noch unterwegs, oder sie wurde von der Stichprobe verworfen.',
        'errors' => 'Ein Fehler wird an dem Schritt markiert, in dem er gemeldet wurde. Von dort führt der Weg zum Fehler selbst — und von jedem Fehler zurück in seine Spur.',
        'select' => 'Ein Klick auf einen Schritt zeigt seine Einzelheiten: das vollständige SQL, das Ziel eines Aufrufs, die Zusatzangaben des SDK. Der geöffnete Schritt steht in der Adresszeile und lässt sich damit verschicken.',
    ],

    'summary' => [
        'duration' => 'Gesamtdauer',
        'services' => 'Dienste',
        'transactions' => 'Transaktionen',
        'spans' => 'Schritte',
        'errors' => 'Fehler',
        'started' => 'Beginn',
        'trace' => 'Spur-Kennung',
        'copy' => 'Kennung kopieren',
    ],

    'empty' => [
        'title' => 'Zu dieser Spur liegt nichts vor.',
        'hint' => 'Entweder ist noch nichts angekommen, oder die Messungen gehören zu Projekten, die du nicht sehen darfst. Möglich ist auch, dass sie inzwischen aufgeräumt wurden.',
    ],

    'truncated' => 'Diese Spur ist größer als das, was hier gezeigt wird: höchstens :transactions Transaktionen, :spans Schritte und :errors Fehler. Der Rest fehlt in der Darstellung.',

    'waterfall' => [
        'heading' => 'Ablauf',
        'expand' => 'Aufklappen',
        'collapse' => 'Zuklappen',
        'expand_all' => 'Alles aufklappen',
        'collapse_all' => 'Alles zuklappen',
        'no_description' => 'ohne Beschreibung',
        'missing' => 'Fehlender Schritt',
        'missing_hint' => 'Auf diesen Schritt wird verwiesen, aber er liegt nicht vor. Was darunter steht, gehört zu ihm.',
        'root' => 'Wurzel',
        'errors' => ':count Fehler in diesem Schritt',
        'error' => 'Ein Fehler in diesem Schritt',
        'rows' => ':shown von :total Zeilen sichtbar',
    ],

    'errors' => [
        'heading' => 'Fehler in dieser Spur',
        'loose' => 'Fehler ohne zugeordneten Schritt',
        'loose_hint' => 'Diese Fehler gehören zur Spur, nennen aber keinen Schritt oder einen, der hier fehlt.',
        'open' => 'Fehler öffnen',
        'no_link' => 'Keine Detailseite verfügbar',
    ],

    'detail' => [
        'heading' => 'Einzelheiten',
        'close' => 'Schließen',
        'loading' => 'Wird geladen …',
        'gone' => 'Zu diesem Schritt liegen keine Einzelheiten (mehr) vor.',
        'description' => 'Beschreibung',
        'operation' => 'Operation',
        'status' => 'Status',
        'project' => 'Dienst',
        'transaction' => 'Transaktion',
        'environment' => 'Umgebung',
        'release' => 'Version',
        'span_id' => 'Schritt-Kennung',
        'parent_span_id' => 'Übergeordneter Schritt',
        'started' => 'Beginn',
        'duration' => 'Dauer',
        'data' => 'Angaben',
        'no_data' => 'Keine Zusatzangaben.',
        'errors' => 'Fehler in diesem Schritt',
    ],
];
