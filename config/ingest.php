<?php

use App\Support\Ingest\Processing\Steps\DecodePayload;
use App\Support\Ingest\Processing\Steps\GroupEvent;
use App\Support\Ingest\Processing\Steps\NormalizeEvent;

return [

    /*
    |--------------------------------------------------------------------------
    | Größenschranken der Datenaufnahme
    |--------------------------------------------------------------------------
    |
    | Beide Grenzen sind nötig, weil sie verschiedene Dinge abwehren:
    |
    |   request  — was über die Leitung kommt, also der noch gepackte Rumpf.
    |              Ohne diese Grenze könnte eine einzelne Anfrage den Speicher
    |              des Prozesses füllen, bevor irgendetwas geprüft wurde.
    |   payload  — was nach dem Entpacken übrig bleibt. Ohne diese Grenze
    |              genügten wenige Kilobyte gut gepackter Nullen, um daraus
    |              Gigabyte zu machen („Zip-Bombe").
    |
    | Die Vorgaben entsprechen Sentry: dort ist eine einzelne Fehlermeldung auf
    | 1 MiB begrenzt. Wer größere Meldungen zulassen will, hebt beide Werte an —
    | die Grenze für den gepackten Rumpf allein zu erhöhen bringt nichts.
    |
    */

    'max_request_bytes' => (int) env('INGEST_MAX_REQUEST_BYTES', 1024 * 1024),

    'max_payload_bytes' => (int) env('INGEST_MAX_PAYLOAD_BYTES', 1024 * 1024),

    /*
    |--------------------------------------------------------------------------
    | Envelopes
    |--------------------------------------------------------------------------
    |
    | Ein Envelope bündelt mehrere Elemente in einer Anfrage und ist deshalb
    | zwangsläufig größer als eine einzelne Meldung — ein Screenshot allein
    | sprengt die 1 MiB von oben. Die Grenzen für Einzelmeldungen mit
    | anzuheben, wäre der falsche Weg: die Werte oben schützen den klassischen
    | Weg, auf dem eine so große Meldung nie berechtigt ist.
    |
    | Die Werte greifen an verschiedenen Stellen:
    |
    |   request      — der noch gepackte Rumpf, wie er über die Leitung kommt.
    |   payload      — der ganze Envelope nach dem Entpacken (gegen „Zip-Bomben").
    |   items        — wie viele Elemente ein Envelope tragen darf. Ohne diese
    |                  Grenze wären es in 20 MiB einige Millionen Zeilen, und
    |                  jede kostet eine Einfügung in die Datenbank.
    |   item         — was ein einzelnes JSON-Element wiegen darf; dieselbe
    |                  Grenze wie für eine Fehlermeldung auf dem alten Weg.
    |   attachment   — dasselbe für Anhänge und Aufzeichnungen. Für die ist
    |                  Größe der Normalfall, für eine Fehlermeldung nicht.
    |
    | Ein Element, das seine Grenze reißt, wird für sich verworfen und gezählt —
    | die übrigen kommen an. Nur ein zu großer Envelope im Ganzen wird
    | abgewiesen (413).
    |
    */

    'envelope' => [

        'max_request_bytes' => (int) env('INGEST_ENVELOPE_MAX_REQUEST_BYTES', 20 * 1024 * 1024),

        'max_payload_bytes' => (int) env('INGEST_ENVELOPE_MAX_PAYLOAD_BYTES', 100 * 1024 * 1024),

        'max_items' => (int) env('INGEST_ENVELOPE_MAX_ITEMS', 100),

        'max_item_bytes' => (int) env('INGEST_ENVELOPE_MAX_ITEM_BYTES', 1024 * 1024),

        'max_attachment_bytes' => (int) env('INGEST_ENVELOPE_MAX_ATTACHMENT_BYTES', 20 * 1024 * 1024),

    ],

    /*
    |--------------------------------------------------------------------------
    | Verarbeitungskette
    |--------------------------------------------------------------------------
    |
    | Die Schritte, die eine angenommene Meldung im Hintergrund durchläuft — in
    | genau dieser Reihenfolge. Jeder Eintrag ist eine Klasse, die
    | App\Support\Ingest\Processing\ProcessingStep umsetzt; erzeugt werden sie
    | über den Dienstbehälter, dürfen also Abhängigkeiten verlangen.
    |
    | Die Reihenfolge ist die eigentliche Aussage dieser Liste, und sie folgt
    | nicht dem Bauchgefühl, sondern zwei Zwängen:
    |
    |   Sparen  — was wegfällt, soll früh wegfallen. Ein Eingangsfilter kostet
    |             einen Vergleich, das Gruppieren kostet einen Fingerabdruck
    |             über den halben Stacktrace. Erst filtern, dann rechnen.
    |   Schutz  — was nie in der Datenbank landen darf, muss entfernt sein,
    |             bevor irgendetwas gespeichert wird. Scrubbing steht deshalb
    |             vor allem, was schreibt, und nicht danach.
    |
    | Die vorgesehene Reihenfolge, mit der Aufgabe, die den jeweiligen Schritt
    | mitbringt:
    |
    |   1. Entpacken     — Rohdaten zu Feld-Baum (Rahmen, hier)
    |   2. Eingangsfilter — uninteressante Meldungen aussortieren (I8)
    |   3. Stichprobe    — Sampling für Performance-Daten (I9)
    |   4. Scrubbing     — personenbezogene Daten entfernen (I7)
    |   5. Normalisierung — Sentry-Schema in unser Modell (I4)
    |   6. Grouping      — Fingerabdruck und Gruppe bestimmen (I5)
    |   7. Aggregation   — Zähler und Issue fortschreiben (I6)
    |
    | Ein neuer Schritt ist eine neue Klasse und eine neue Zeile. Ein
    | bestehender Schritt wird dafür nicht angefasst — auch nicht der Rahmen.
    |
    */

    'processing' => [

        'steps' => [
            DecodePayload::class,
            NormalizeEvent::class,
            GroupEvent::class,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Gruppierung
    |--------------------------------------------------------------------------
    |
    | Gleichartige Meldungen bekommen denselben Fingerabdruck und landen damit in
    | einer Gruppe. Wonach gruppiert wird, steht nicht hier, sondern in
    | App\Support\Ingest\Grouping — es ist ein Verfahren und keine Einstellung.
    | Wer im Einzelfall etwas anderes braucht, schreibt eine projektweite
    | Fingerprint-Regel; die stehen in der Datenbank und nicht in dieser Datei,
    | weil sie je Projekt verschieden sind.
    |
    |   max_frames — wie viele Stapelrahmen in den Fingerabdruck eingehen.
    |
    | Der Wert ist eine Abwägung und keine Sparmaßnahme. Zu wenige Rahmen: zwei
    | verschiedene Fehler, die über dieselbe Hilfsfunktion laufen, fallen
    | zusammen. Zu viele: ein Fehler, der aus zwei verschiedenen Tiefen desselben
    | Rahmenwerks kommt, fällt auseinander. Dreißig deckt den eigenen Code
    | üblicher Anwendungen ab — und nur der geht ein, solange das SDK `in_app`
    | setzt.
    |
    | **Wird dieser Wert geändert, ändern sich Fingerabdrücke.** Laufende Fehler
    | bekommen dann eine zweite Gruppe, ihre Zählung beginnt bei eins, und
    | Alarmregeln melden einen neuen Fehler. Eine Änderung gehört deshalb
    | zusammen mit einer erneuten Auswertung der betroffenen Zeiträume — nicht
    | zwischendurch.
    |
    */

    'grouping' => [

        'max_frames' => (int) env('INGEST_GROUPING_MAX_FRAMES', 30),

    ],

    /*
    |--------------------------------------------------------------------------
    | Normalisierung
    |--------------------------------------------------------------------------
    |
    | Die Aufnahme kennt nur eine Grenze: wie groß eine Meldung im Ganzen sein
    | darf. Das genügt hier nicht. Eine Meldung von knapp einem Megabyte ist
    | erlaubt — sie darf aber nicht aus einem einzigen Fehlertext von einem
    | Megabyte bestehen, denn der wird in Listen, Suchergebnissen, E-Mails und
    | Chat-Nachrichten wieder ausgepackt, und dort kostet er jedes Mal erneut.
    | Die Werte hier verteilen das erlaubte Gesamtgewicht auf die Abschnitte.
    |
    | Gekürzt wird sichtbar: jede Kürzung steht danach unter `notes.truncated`
    | am Datensatz. Ein abgeschnittener Stacktrace, der aussieht wie ein kurzer,
    | schickt den Suchenden an die falsche Stelle — das ist teurer als der
    | Speicher, den die Grenze spart.
    |
    |   string_chars       — Zeichen je Textfeld (Fehlertext, Dateiname …).
    |   source_line_chars  — Zeichen je Zeile Quelltext. Deutlich enger: eine
    |                        gebündelte JavaScript-Datei besteht aus wenigen
    |                        Zeilen von je hunderten Kilobyte.
    |   exceptions         — verschachtelte Ursachen („caused by") je Meldung.
    |   frames             — Stapelrahmen je Stacktrace.
    |   context_lines      — Quelltextzeilen vor und nach der Fehlerstelle.
    |   threads            — Ausführungsstränge je Meldung.
    |   breadcrumbs        — Spuren je Meldung; gekappt wird das Älteste.
    |   entries            — Einträge je Schlüssel-Wert-Abschnitt (Marken,
    |                        Kopfzeilen, Umgebungsvariablen …).
    |   depth              — Verschachtelungstiefe in frei geformten
    |                        Abschnitten. Ohne diese Grenze genügte ein tief
    |                        gebauter Feld-Baum, um die Auswertung anzuhalten.
    |
    */

    'normalization' => [

        'limits' => [
            'string_chars' => (int) env('INGEST_NORMALIZE_STRING_CHARS', 8192),
            'source_line_chars' => (int) env('INGEST_NORMALIZE_SOURCE_LINE_CHARS', 512),
            'exceptions' => (int) env('INGEST_NORMALIZE_MAX_EXCEPTIONS', 25),
            'frames' => (int) env('INGEST_NORMALIZE_MAX_FRAMES', 250),
            'context_lines' => (int) env('INGEST_NORMALIZE_MAX_CONTEXT_LINES', 10),
            'threads' => (int) env('INGEST_NORMALIZE_MAX_THREADS', 25),
            'breadcrumbs' => (int) env('INGEST_NORMALIZE_MAX_BREADCRUMBS', 100),
            'entries' => (int) env('INGEST_NORMALIZE_MAX_ENTRIES', 100),
            'depth' => (int) env('INGEST_NORMALIZE_MAX_DEPTH', 5),
        ],

    ],

];
