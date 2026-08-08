<?php

namespace App\Support\Performance\Detection;

/**
 * Die Form einer Abfrage: dasselbe SQL ohne seine Werte.
 *
 * Das ist die Voraussetzung für die ganze Erkennung. `select * from users where
 * id = 1` und `… where id = 2` sind für die Datenbank zwei Abfragen und für die
 * Suche nach einem N+1 **eine**: genau daran erkennt man die Schleife. Ohne
 * diese Normalisierung fände kein Erkenner je eine Wiederholung, weil sich die
 * Kennung im Text unterscheidet.
 *
 * **Kein SQL-Parser.** Was hier passiert, ist bewusst grob: Zahlen und
 * Zeichenketten werden zu einem Platzhalter, Listen in `in (…)` zu einem
 * einzigen, Weißraum vereinheitlicht. Ein richtiger Parser würde jede
 * SQL-Variante kennen müssen — und was der Erkenner braucht, ist keine
 * Zerlegung, sondern eine stabile Kennung. Die Form dient ausdrücklich nur dem
 * **Vergleich**; angezeigt wird die echte Abfrage aus dem Beleg.
 *
 * Sie ist auch für alles andere brauchbar: bei einem HTTP-Aufruf verschwinden
 * die Kennungen aus dem Pfad, bei einer Datei die Versionsnummer. Derselbe
 * Zweck, dieselbe Behandlung.
 */
final class QueryShape
{
    /**
     * Ab wann eine Form gekürzt wird.
     *
     * Sie geht in den Fingerabdruck ein und wird dort ohnehin zu einem
     * Streuwert; als Text steht sie zusätzlich am Fund. Eine Abfrage über
     * achtzig Spalten mit fünf Unterabfragen bringt nach ein paar hundert
     * Zeichen keine Unterscheidung mehr — nur noch Platzverbrauch in jeder
     * Zeile.
     */
    public const LIMIT = 500;

    private const PLACEHOLDER = '?';

    /**
     * Die Form einer Beschreibung — leer, wenn es keine gibt.
     */
    public static function of(?string $description): string
    {
        if ($description === null) {
            return '';
        }

        $shape = trim($description);

        if ($shape === '') {
            return '';
        }

        // Zeichenketten zuerst: was in Anführungszeichen steht, kann Zahlen,
        // Klammern und Kommas enthalten, und jede spätere Regel würde darin
        // herumschneiden. Escapte Anführungszeichen bleiben Teil der
        // Zeichenkette — sonst endet sie zu früh und der Rest der Abfrage wird
        // als Text gelesen.
        $shape = (string) preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/", self::PLACEHOLDER, $shape);
        $shape = (string) preg_replace('/"(?:[^"\\\\]|\\\\.)*"/', self::PLACEHOLDER, $shape);

        // Fertige Platzhalter der Treiber — `$1`, `:name` — sind schon
        // Platzhalter; sie werden nur vereinheitlicht. Vor den Zahlen, sonst
        // bliebe von `$1` ein `$?` übrig, das keine der folgenden Regeln mehr
        // als Platzhalter erkennt.
        $shape = (string) preg_replace('/[$:]\w+/', self::PLACEHOLDER, $shape);

        // Zahlen, aber nur als eigenständiger Wert: `utf8mb4` und `md5_hash`
        // sind Namen und keine Werte, und wer dort die Ziffern ersetzt, macht
        // aus zwei verschiedenen Spalten dieselbe.
        $shape = (string) preg_replace('/\b\d+(?:\.\d+)?\b/', self::PLACEHOLDER, $shape);

        // Eine Liste von Platzhaltern ist ein Platzhalter. Sonst wäre
        // `in (?, ?)` eine andere Abfrage als `in (?, ?, ?)` — und genau die
        // wechselnde Länge ist das Kennzeichen einer gebündelten Abfrage, also
        // des Gegenteils eines Problems.
        $shape = (string) preg_replace('/\(\s*\?(?:\s*,\s*\?)*\s*\)/', '(?)', $shape);

        // Zeilenumbrüche und mehrfache Leerzeichen: dieselbe Abfrage, einmal
        // aus einem Query-Builder und einmal von Hand geschrieben.
        $shape = trim((string) preg_replace('/\s+/', ' ', $shape));

        if (mb_strlen($shape) > self::LIMIT) {
            $shape = mb_substr($shape, 0, self::LIMIT).'…';
        }

        return $shape;
    }

    /**
     * Die Form einer Adresse: ohne Abfrageteil, ohne Kennungen im Pfad.
     *
     * Getrennt von {@see of()}, weil eine Adresse andere Wegwerf-Teile hat als
     * eine Abfrage. `/api/nutzer/4711/rechnungen?seite=2` und
     * `/api/nutzer/4712/rechnungen?seite=3` sind derselbe Aufruf; wer sie
     * auseinanderhält, bekommt je Kunde ein eigenes Leistungsproblem.
     */
    public static function ofUrl(?string $url): string
    {
        if ($url === null || trim($url) === '') {
            return '';
        }

        $url = trim($url);

        // Der Abfrageteil und der Anker fliegen ganz heraus: dort stehen
        // Sitzungskennungen und Zeitstempel, und kein Teil davon unterscheidet
        // zwei Aufrufe fachlich.
        $url = (string) preg_replace('/[?#].*$/', '', $url);

        // Zahlen-Segmente und lange Hex-Kennungen im Pfad sind Werte.
        $url = (string) preg_replace('#/\d+#', '/'.self::PLACEHOLDER, $url);
        $url = (string) preg_replace('#/[0-9a-f]{8,}#i', '/'.self::PLACEHOLDER, $url);
        $url = (string) preg_replace(
            '#/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}#i',
            '/'.self::PLACEHOLDER,
            $url,
        );

        if (mb_strlen($url) > self::LIMIT) {
            $url = mb_substr($url, 0, self::LIMIT).'…';
        }

        return $url;
    }
}
