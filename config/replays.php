<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ablage der Aufzeichnungen
    |--------------------------------------------------------------------------
    |
    | Die Aufzeichnung einer Sitzung liegt nicht in der Datenbank, sondern auf
    | einem Laufwerk — dieselbe Begründung wie bei den Quellkarten, nur deutlich
    | schärfer: eine einzelne Sitzung von zehn Minuten bringt es je nach Anwendung
    | auf mehrere Megabyte, sie wird **immer** als Ganzes gelesen und nie
    | durchsucht. In der Datenbank wäre sie ein Feld, das jede Auswertung über die
    | Nachbarspalten mitschleppt.
    |
    | Das ist zugleich die Zusage aus der Aufgabe: **Aufzeichnungen liegen
    | getrennt von den Ereignisdaten und lassen sich getrennt löschen.** Ein
    | Betreiber, der Replays abschalten und ihren Platz zurückhaben will, wirft
    | einen Ordner weg; die Fehler bleiben davon unberührt. Umgekehrt nimmt das
    | Löschen eines Projekts die Zeilen über den Fremdschlüssel mit — und die
    | Dateien räumt {@see App\Console\Commands\SweepReplaysCommand} nach, der
    | ohnehin täglich läuft.
    |
    | Der Ablagepfad ist `<pfad>/<projekt>/<aufzeichnung>/<abschnitt>.json.gz`.
    | Die Aufzeichnung steht als eigener Ordner darin, weil genau er die Einheit
    | des Löschens ist — eine Aufbewahrungsfrist, die einzelne Dateien suchen
    | müsste, wäre bei Millionen Abschnitten kein Aufräumen mehr.
    |
    */

    'disk' => env('REPLAYS_DISK', 'local'),

    'path' => env('REPLAYS_PATH', 'replays'),

    /*
    |--------------------------------------------------------------------------
    | Aufbewahrung
    |--------------------------------------------------------------------------
    |
    | Wie lange eine Aufzeichnung liegen bleibt, wenn ein Projekt nichts anderes
    | einstellt (`projects.replay_retention_days`).
    |
    | **Der Wert ist bewusst kürzer als die Aufbewahrung der Ereignisse.** Eine
    | Aufzeichnung ist um Größenordnungen schwerer als der Fehler, zu dem sie
    | gehört, und ihr Wert verfällt schneller: sie beantwortet „was hat der
    | Nutzer vorhin getan", und diese Frage stellt niemand mehr, wenn der Fehler
    | seit vier Wochen bearbeitet ist. Dieselbe Frist wie für Ereignisse zu
    | nehmen hieße, den Speicherbedarf an eine Zahl zu hängen, die für etwas
    | anderes gewählt wurde.
    |
    | Die Frist ist deshalb je Projekt **getrennt** einstellbar und nicht von der
    | Ereignisfrist abgeleitet. Das ist die zweite Zusage aus der Aufgabe.
    |
    */

    'retention_days' => (int) env('REPLAYS_RETENTION_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Grenzen
    |--------------------------------------------------------------------------
    |
    | Wie groß eine einzelne Aufzeichnung werden darf, kommt nicht aus einer
    | Zahl, sondern aus dreien — sie fangen verschiedene Fälle ab:
    |
    |   max_segments        — wie viele Abschnitte eine Sitzung tragen darf. Das
    |                         SDK schneidet die Aufnahme in Abschnitte von etwa
    |                         fünf Sekunden; die Zahl ist damit eine Grenze für
    |                         die **Dauer**. 1200 Abschnitte sind grob zwei
    |                         Stunden — länger sieht sich ohnehin niemand an, und
    |                         eine offene Registerkarte über Nacht ist keine
    |                         Sitzung, sondern ein Speicherleck.
    |   max_total_bytes     — was alle Abschnitte zusammen wiegen dürfen. Nötig
    |                         neben der Anzahl, weil eine Anwendung mit ständig
    |                         wechselndem Bildschirminhalt in derselben Zeit ein
    |                         Vielfaches meldet.
    |   max_events_per_segment — wie viele rrweb-Ereignisse ein Abschnitt tragen
    |                         darf. Die Grenze schützt nicht vor Angreifern —
    |                         dafür sorgt die Größe des Envelope-Elements —,
    |                         sondern vor dem Regelfall: eine Seite mit einer
    |                         Animation meldet für fünf Sekunden zehntausende
    |                         Mausbewegungen.
    |
    | Was über eine der Grenzen geht, wird für sich verworfen und gezählt. Die
    | Aufzeichnung bleibt bestehen und abspielbar — sie endet dann eben früher.
    | Eine Sitzung wegen ihres letzten Abschnitts ganz wegzuwerfen wäre die
    | falsche Antwort: der interessante Teil ist der Anfang, nicht das Ende.
    |
    */

    'max_segments' => (int) env('REPLAYS_MAX_SEGMENTS', 1200),

    'max_total_bytes' => (int) env('REPLAYS_MAX_TOTAL_BYTES', 100 * 1024 * 1024),

    'max_events_per_segment' => (int) env('REPLAYS_MAX_EVENTS_PER_SEGMENT', 20000),

    /*
    |--------------------------------------------------------------------------
    | Untätigkeit
    |--------------------------------------------------------------------------
    |
    | Ab welcher Pause zwischen zwei Abschnitten eine Sitzung als beendet gilt.
    |
    | Das SDK schickt keinen Schlusspunkt: ein Nutzer, der die Registerkarte
    | schließt, meldet sich nicht ab. Ohne diese Grenze wäre jede Aufzeichnung
    | „läuft noch", und die Liste könnte nicht sagen, wie lange eine Sitzung
    | gedauert hat. Fünfzehn Minuten sind dieselbe Grenze, die das SDK selbst für
    | seine Sitzungen verwendet — eine kürzere würde Sitzungen zerschneiden, in
    | denen jemand nur zum Kaffee war.
    |
    */

    'idle_minutes' => (int) env('REPLAYS_IDLE_MINUTES', 15),

    /*
    |--------------------------------------------------------------------------
    | Maskierung
    |--------------------------------------------------------------------------
    |
    | **Maskiert wird im Browser, nicht hier.** Das ist keine Bequemlichkeit,
    | sondern der einzige Ort, an dem es etwas nützt: was einmal gesendet wurde,
    | ist gesendet, und ein Server, der Eingaben nachträglich schwärzt, hat sie
    | vorher entgegengenommen. Die Maskierung des SDK ersetzt Texte und
    | Eingabefelder, **bevor** ein Abschnitt den Rechner des Nutzers verlässt.
    |
    | Diese Anwendung kann daran zweierlei tun, und beides tut sie:
    |
    |   1. Die Einrichtungs-Anleitung ({@see App\Support\Setup\SetupGuide}) zeigt
    |      die Aufnahme **nur** mit eingeschalteter Maskierung. Wer eine Anleitung
    |      kopiert, bekommt den sicheren Fall — und muss die Maskierung
    |      ausdrücklich abschalten, statt sie ausdrücklich einzuschalten.
    |   2. Beim Annehmen wird festgehalten, ob das SDK maskiert gemeldet hat
    |      ({@see App\Support\Replays\ReplayMetadata}), und die Abspielseite sagt
    |      es dazu. Eine unmaskierte Aufzeichnung ist damit erkennbar, statt
    |      unbemerkt zu bleiben.
    |
    | `require_masking` ist die dritte, schärfste Stufe: angenommen werden dann
    | nur Aufzeichnungen, die sich als maskiert ausweisen. Standardmäßig aus,
    | weil ältere SDKs die Angabe nicht mitschicken und ihre Aufzeichnungen sonst
    | wortlos verschwänden — wer den Schalter umlegt, weiß, was er von seinen
    | SDKs erwartet.
    |
    */

    'require_masking' => (bool) env('REPLAYS_REQUIRE_MASKING', false),

];
