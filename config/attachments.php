<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ablage der Anhänge
    |--------------------------------------------------------------------------
    |
    | Screenshots, Logdateien und Speicherabbilder liegen nicht in der Datenbank,
    | sondern auf einem Laufwerk aus `config/filesystems.php` — im Betrieb ein
    | Objektspeicher, in der Entwicklung die lokale Platte, im Test ein
    | vorgetäuschtes Laufwerk. Die Datenbank trägt nur den Verweis.
    |
    | Der Grund ist derselbe wie bei den Quellkarten (`config/sourcemaps.php`):
    | eine Datei wird nur als Ganzes gelesen, und ein Speicherabbild von zwanzig
    | Megabyte in einer Textspalte macht jede Abfrage teuer, die die Zeile bloß
    | streift.
    |
    | Der Ablagepfad ist der Prüfsummenpfad (`sha1` des Inhalts) innerhalb des
    | Projekts. Ein Screenshot, den ein SDK zu jeder Meldung derselben Sitzung
    | mitschickt, belegt damit einmal Platz statt hundertmal — bei einem
    | Absturzdialog mit „erneut versuchen" ist das der Regelfall.
    |
    */

    'disk' => env('ATTACHMENTS_DISK', 'local'),

    'path' => env('ATTACHMENTS_PATH', 'event-attachments'),

    /*
    |--------------------------------------------------------------------------
    | Grenzen
    |--------------------------------------------------------------------------
    |
    | Zwei Grenzen, und beide fangen verschiedene Fälle ab:
    |
    |   max_bytes      — wie groß ein einzelner Anhang sein darf. Die Aufnahme
    |                    hat mit `ingest.envelope.max_attachment_bytes` schon
    |                    eine Grenze; die hier ist die des **Betreibers** und
    |                    darf enger sein. Sie greift in der Verarbeitung, wo das
    |                    Projekt bekannt ist — die andere greift an einer Stelle,
    |                    die nur den Envelope kennt.
    |   max_per_event  — wie viele Anhänge eine Meldung tragen darf. Die Zahl
    |                    schützt nicht vor einer großen Datei, sondern vor einem
    |                    SDK, das bei jedem Wiederholungsversuch denselben
    |                    Screenshot noch einmal schickt: ohne sie hängen an einer
    |                    Meldung irgendwann hundert Bilder, und die Seite lädt
    |                    sie alle.
    |
    | Was eine Grenze reißt, wird für sich verworfen und gezählt
    | ({@see App\Support\Ingest\Processing\Steps\StoreAttachment}) — die Meldung,
    | zu der der Anhang gehört, kommt trotzdem an. Der Grund steht in der
    | Verworfen-Statistik, damit ein fehlender Screenshot erklärbar bleibt statt
    | still zu verschwinden.
    |
    */

    'max_bytes' => (int) env('ATTACHMENTS_MAX_BYTES', 20 * 1024 * 1024),

    'max_per_event' => (int) env('ATTACHMENTS_MAX_PER_EVENT', 20),

    /*
    |--------------------------------------------------------------------------
    | Aufbewahrung
    |--------------------------------------------------------------------------
    |
    | Wie lange ein Anhang liegen bleibt, steht am Projekt
    | (`projects.attachment_retention_days`) — dieser Wert gilt für ein neu
    | angelegtes Projekt.
    |
    | Sieben Tage und nicht dreißig wie bei den Ereignissen: ein Anhang ist ein
    | Vielfaches schwerer als die Meldung, an der er hängt, und gebraucht wird er
    | in den Tagen, in denen jemand den Fehler untersucht. Die eigene Frist ist
    | genau deshalb eine eigene — wer Ereignisse ein Jahr behalten will, will
    | nicht ein Jahr Speicherabbilder behalten.
    |
    */

    'retention_days' => (int) env('ATTACHMENTS_RETENTION_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Vorschau
    |--------------------------------------------------------------------------
    |
    | Ein Bild wird angezeigt, eine Textdatei angerissen, alles andere nur zum
    | Herunterladen angeboten.
    |
    |   image_types    — Inhaltstypen, die als Bild im Browser dargestellt
    |                    werden. Eine Aufzählung und kein `image/*`: der Browser
    |                    bekommt die Datei damit **inline** ausgeliefert, und was
    |                    inline geht, ist eine Sicherheitsentscheidung. `image/svg+xml`
    |                    fehlt deshalb bewusst — eine SVG-Datei ist ein Dokument
    |                    mit Skriptmöglichkeit und kein Bild.
    |   text_types     — Inhaltstypen, aus denen ein Textanriss gezeigt wird.
    |   preview_bytes  — wie viel vom Anfang einer Textdatei dafür gelesen wird.
    |                    Eine Logdatei von zwanzig Megabyte ist im Browser keine
    |                    Vorschau, sondern eine hängende Seite.
    |
    */

    'preview' => [

        'image_types' => [
            'image/png',
            'image/jpeg',
            'image/gif',
            'image/webp',
            'image/avif',
        ],

        'text_types' => [
            'text/plain',
            'text/csv',
            'application/json',
            'application/x-ndjson',
            'application/xml',
            'text/xml',
        ],

        'preview_bytes' => (int) env('ATTACHMENTS_PREVIEW_BYTES', 64 * 1024),

    ],

];
