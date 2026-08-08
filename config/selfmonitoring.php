<?php

/*
|--------------------------------------------------------------------------
| Selbstüberwachung
|--------------------------------------------------------------------------
|
| Errstack meldet an Errstack. Die Wege dorthin sind dieselben, die eine
| fremde Anwendung nimmt — es gibt keine Abkürzung ins eigene Datenmodell
| vorbei an der Datenaufnahme. Das ist der Punkt der Übung: was hier
| ankommt, beweist, dass der Weg funktioniert.
|
| Alles hängt an **einer** Angabe, `ERRSTACK_DSN` (siehe config/sentry.php).
| Aus ihr leiten sich Rechner, Projekt und Schlüssel für alle vier Wege ab
| ({@see App\Support\SelfMonitoring\Dsn}) — Serverfehler und Antwortzeiten
| über das PHP-SDK, Browserfehler und Ladeerlebnis über das JS-SDK, die
| Sicherheitsberichte über die Kopfzeile und der Zeitplan über sein
| Lebenszeichen.
|
| Ohne DSN meldet nichts. Das ist der Auslieferungszustand und kein Fehler.
|
*/

return [

    /*
    | Der Browser meldet mit.
    |
    | Ein Teil der Oberfläche läuft dort, und was dort bricht, sieht der
    | Server nicht: eine gescheiterte Inertia-Antwort, ein Fehler beim
    | Zeichnen einer Ansicht, ein Websocket, der nicht zustande kommt. Ohne
    | diesen Weg wäre die Selbstüberwachung auf die halbe Anwendung blind.
    |
    | `traces_sample_rate` steuert hier dasselbe wie serverseitig, ist aber
    | getrennt einstellbar: im Browser hängt an einer Messung das
    | Ladeerlebnis (Web Vitals), und davon will man in der Regel mehr sehen
    | als von den Antwortzeiten des Servers.
    */
    'browser' => [

        'enabled' => (bool) env('ERRSTACK_BROWSER_ENABLED', true),

        'traces_sample_rate' => env('ERRSTACK_BROWSER_TRACES_SAMPLE_RATE') === null
            ? null
            : (float) env('ERRSTACK_BROWSER_TRACES_SAMPLE_RATE'),

    ],

    /*
    | Die Sicherheitsberichte des Browsers (CSP).
    |
    | Ausgeliefert wird `Content-Security-Policy-Report-Only`: die Regel
    | meldet, sie blockiert nicht. Eine schärfende Regel gehört zur Härtung
    | der Anwendung und nicht zur Überwachung — hier geht es darum, dass die
    | Berichte den Weg in die eigene Aufnahme finden.
    |
    | `directives` ist bewusst knapp gehalten. Jede Zeile mehr ist eine
    | Aussage darüber, was die Oberfläche darf, und die veraltet ohne
    | Vorwarnung; was hier steht, beschreibt nur, woher überhaupt etwas
    | geladen wird.
    */
    'csp' => [

        'enabled' => (bool) env('ERRSTACK_CSP_REPORTS_ENABLED', true),

        'directives' => [
            "default-src 'self'",
            "img-src 'self' data:",
            "style-src 'self' 'unsafe-inline'",
            "script-src 'self' 'unsafe-inline'",
            "connect-src 'self' ws: wss: https:",
            "font-src 'self' data:",
            "frame-ancestors 'none'",
        ],

    ],

    /*
    | Der Zeitplan meldet, dass er gelaufen ist.
    |
    | Das ist die einzige Auskunft, die nicht von selbst entsteht: ein
    | Zeitplan, der gar nicht mehr läuft, meldet auch keinen Fehler. Genau
    | diesen Fall macht das Lebenszeichen sichtbar — und er ist bei dieser
    | Anwendung keiner von vielen, sondern der schwerwiegendste: an
    | `schedule:run` hängt die Überwachung **aller** fremden Cronjobs
    | ({@see routes/console.php}).
    |
    | `monitor` ist die Kennung, unter der der Job auf der empfangenden
    | Installation angelegt sein muss. Ist dort keiner mit diesem Namen,
    | nimmt die Aufnahme das Lebenszeichen an und ordnet es niemandem zu —
    | gemeldet wird dann nichts, und es fällt nicht auf.
    */
    'schedule' => [

        'enabled' => (bool) env('ERRSTACK_SCHEDULE_CHECKINS_ENABLED', true),

        'monitor' => env('ERRSTACK_SCHEDULE_MONITOR', 'zeitplan'),

        // Wie lange ein Lebenszeichen auf Antwort warten darf. Kurz, weil es
        // vor und nach jedem Durchlauf des Zeitplans anfällt: eine langsame
        // Gegenstelle darf den Zeitplan nicht ausbremsen, dessen Pünktlichkeit
        // sie gerade überwachen soll.
        'timeout_seconds' => (int) env('ERRSTACK_SCHEDULE_TIMEOUT_SECONDS', 5),

    ],

];
