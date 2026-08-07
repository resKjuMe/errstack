<?php

// Musterseite „Bausteine" und die Texte der gemeinsamen Bausteine
// (Toast, Live-Vorführung).
return [

    'title' => 'Bausteine',
    'help' => 'Jeder Baustein einmal in Aktion — zum Prüfen von Hell-/Dunkelmodus und Verhalten.',

    'flash' => [
        'title' => 'Meldungen (Flash)',
        'description' => 'Werden aus der Session gelesen und oben im Inhalt gezeigt.',
        'example_status' => 'Die Änderungen wurden gespeichert.',
        'example_error' => 'Der Vorgang konnte nicht abgeschlossen werden.',
        'example_name_error' => 'Der Name ist erforderlich.',
        'example_email_error' => 'Die E-Mail-Adresse ist ungültig.',
    ],

    'toasts' => [
        'title' => 'Toasts',
        'description' => 'Kurzlebige Rückmeldungen rechts unten, unabhängig vom Seiteninhalt.',
        'success' => 'Erfolg',
        'error' => 'Fehler',
        'info' => 'Hinweis',
        'example_success' => 'Die Änderungen wurden gespeichert.',
        'example_error' => 'Das hat leider nicht geklappt.',
        'example_info' => 'Zur Kenntnis genommen.',
        'dismiss' => 'Meldung schließen',
    ],

    'skeleton' => [
        'title' => 'Ladeplatzhalter (Skeleton)',
        'description' => 'Bis die Daten da sind — gleiche Grautöne wie das restliche UI.',
    ],

    'live' => [
        'title' => 'Hintergrund-Verarbeitung',
        'description' => 'Der Job läuft in der Warteschlange „ingest"; sein Ergebnis kommt per Broadcast zurück.',
        'dispatch' => 'Ingest einreihen',
        'fail' => 'Fehlschlag erzwingen',
        'dispatch_failed' => 'Der Job konnte nicht eingereiht werden.',
        'disabled' => 'Live-Aktualisierung ist aus: BROADCAST_CONNECTION und die Verbindungsdaten sind nicht gesetzt. Der Job läuft trotzdem — sichtbar in der Worker-Ausgabe.',
        'empty' => 'Noch nichts eingegangen — Worker läuft?',
    ],

];
