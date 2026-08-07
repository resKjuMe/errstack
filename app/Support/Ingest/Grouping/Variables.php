<?php

namespace App\Support\Ingest\Grouping;

/**
 * Ersetzt die wechselnden Anteile eines Textes durch Platzhalter.
 *
 * Das ist der Kern der Gruppierung und zugleich ihre gefährlichste Stelle. Zwei
 * Fehler sind möglich, und sie sind nicht gleich schlimm:
 *
 * **Zu wenig ersetzen** heißt, dass „Nutzer 4711 nicht gefunden" und „Nutzer
 * 4712 nicht gefunden" zwei Gruppen ergeben — und bei zehntausend Nutzern
 * zehntausend. Genau die Flut, gegen die diese Aufgabe angeht.
 *
 * **Zu viel ersetzen** heißt, dass „Zeitüberschreitung nach 30 s" und
 * „Zeitüberschreitung nach 3000 s" als derselbe Fehler gelten, obwohl das eine
 * ein Aussetzer und das andere ein Hänger ist. Das ist der teurere Fehler: eine
 * zu feine Gruppierung sieht man sofort und korrigiert sie mit einer Regel, eine
 * zu grobe verbirgt eine Ursache hinter einer anderen, und niemand sucht nach
 * dem, was er nicht sieht.
 *
 * Deshalb wird hier nur ersetzt, was **erkennbar** eine Kennung ist, und nicht
 * jede Ziffer:
 *
 * - Speicheradressen, UUIDs, lange Hex-Ketten und Base64-artige Zeichenketten —
 *   sie tragen nie eine Bedeutung, die zwei Fehler unterscheidet.
 * - Zeitpunkte, IP-Adressen, E-Mail-Adressen — sie beschreiben den Einzelfall,
 *   nicht den Fehler.
 * - Frei stehende Zahlen ab {@see MIN_DIGITS} Stellen. Die Grenze ist die
 *   Abwägung in Zahlenform: `4711` ist eine Kennung, `30` ist eine Angabe.
 *
 * Die Reihenfolge der Ersetzungen ist Absicht und nicht beliebig: die enger
 * gefassten Muster zuerst, sonst zerlegt die allgemeine Zahlenregel eine UUID
 * in Bruchstücke, bevor sie als UUID erkannt wird.
 */
final class Variables
{
    /**
     * Ab wie vielen Stellen eine frei stehende Zahl als Kennung gilt.
     *
     * Vier und nicht eins: Statuscodes (`404`, `500`), Sekunden (`30`),
     * Anzahlen (`3 von 5`), Jahreszahlen — alles Angaben zum Fehler, die ihn
     * unterscheiden sollen. Was eine laufende Nummer ist, hat in der Praxis
     * mehr Stellen.
     */
    private const MIN_DIGITS = 4;

    /**
     * Die Ersetzungen in ihrer verbindlichen Reihenfolge: Muster => Platzhalter.
     *
     * @var array<string, string>
     */
    private const RULES = [
        // Speicheradressen und Zeiger. Sie stehen in Stacktraces nativer
        // Anwendungen in jedem Rahmen und sind bei jedem Start andere.
        '/\b0x[0-9a-f]+\b/i' => '<addr>',

        // UUIDs, mit und ohne Bindestriche.
        '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i' => '<uuid>',

        // Zeitpunkte nach ISO 8601 — vor der Datums- und der Zahlenregel, weil
        // sie beide enthalten.
        '/\b\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?/i' => '<datetime>',

        // Datum und Uhrzeit für sich.
        '/\b\d{4}-\d{2}-\d{2}\b/' => '<date>',
        '/\b\d{2}:\d{2}:\d{2}(?:\.\d+)?\b/' => '<time>',

        // E-Mail-Adressen. Vor der IP-Regel, weil eine Adresse eine Zahl im
        // Namensteil tragen darf.
        '/\b[\w.+-]+@[\w-]+(?:\.[\w-]+)+\b/u' => '<email>',

        // IP-Adressen, v4 und v6 in ihrer vollen Schreibweise.
        '/\b\d{1,3}(?:\.\d{1,3}){3}\b/' => '<ip>',
        '/\b(?:[0-9a-f]{1,4}:){7}[0-9a-f]{1,4}\b/i' => '<ip>',

        // Prüfsummen und Kennungen in Hex: md5, sha1, sha256, Commit-Hashes.
        // Erst ab acht Stellen, damit `deadbeef` als Wort nicht verschwindet —
        // und ausdrücklich mit mindestens einer Ziffer, sonst fiele jedes
        // hinreichend lange Wort aus den Buchstaben a bis f darunter.
        '/\b(?=[0-9a-f]*\d)[0-9a-f]{8,}\b/i' => '<hash>',

        // Base64-artige Zeichenketten: Tokens, Signaturen, gepackte Nutzdaten.
        // Die Länge ist die Sicherung — kürzere Ketten sind zu oft echte Wörter.
        '/\b(?=[A-Za-z0-9+\/_-]*\d)(?=[A-Za-z0-9+\/_-]*[A-Z])[A-Za-z0-9+\/_-]{24,}={0,2}/' => '<token>',

        // Frei stehende Zahlen ab der Mindestlänge, samt Vor- und Nachkommateil.
        '/\b\d{'.self::MIN_DIGITS.',}(?:[.,]\d+)?\b/' => '<n>',
    ];

    /**
     * Ersetzt die wechselnden Anteile und räumt den Text auf.
     *
     * Der Rückgabewert ist `null`, wenn nach der Ersetzung nichts Tragfähiges
     * übrig bleibt. Das ist kein Sonderfall, sondern der Normalfall bei einem
     * Text, der **nur** aus einer Kennung bestand: er unterscheidet dann nichts
     * mehr und darf deshalb auch nicht als Bestandteil gelten — sonst hinge die
     * Gruppe an einem Platzhalter.
     */
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = $value;

        foreach (self::RULES as $pattern => $placeholder) {
            $replaced = preg_replace($pattern, $placeholder, $normalized);

            // `preg_replace` gibt bei einem Aussetzer `null` zurück. Den Text
            // dann zu verwerfen wäre die falsche Antwort: lieber ohne diese
            // eine Ersetzung weiter als ohne den ganzen Bestandteil.
            if ($replaced !== null) {
                $normalized = $replaced;
            }
        }

        // Mehrfache Leerzeichen entstehen erst durch die Ersetzungen und wären
        // sonst ein Unterschied ohne Bedeutung.
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
        $normalized = trim($normalized);

        if ($normalized === '') {
            return null;
        }

        // Ein Text, in dem nur noch Platzhalter stehen, trägt keine Aussage
        // mehr. Er würde jede Meldung mit derselben Form in eine Gruppe ziehen
        // — unabhängig davon, worum es ging.
        if (preg_match('/^(?:<[a-z]+>[\s\p{P}]*)+$/u', $normalized) === 1) {
            return null;
        }

        return $normalized;
    }

    /**
     * Räumt einen Pfad auf, bevor er in den Fingerabdruck geht.
     *
     * Pfade sind der zweite große Streuer neben Zahlen in Texten, aber aus
     * anderen Gründen: dieselbe Datei liegt auf jedem Rechner woanders
     * (`/home/anna/projekt`, `C:\build\42\projekt`, `/var/task`), und
     * gebündeltes JavaScript trägt bei jedem Bau einen neuen Namen
     * (`app.4f3a2b1c.js`). Beides ändert den Ort, nicht den Fehler.
     *
     * Was **nicht** geschieht: den Pfad auf den Dateinamen einzukürzen. Zwei
     * gleichnamige `index.js` aus verschiedenen Modulen sind verschiedene
     * Dateien, und sie zusammenzuwerfen wäre der teure Fehler von oben.
     */
    public static function path(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        $normalized = str_replace('\\', '/', trim($path));

        if ($normalized === '') {
            return null;
        }

        // Adressen als Pfad (`https://example.test/js/app.js`): der Rechnername
        // wechselt zwischen Umgebungen und Auslieferungsnetzen, der Pfad nicht.
        $normalized = preg_replace('#^[a-z][a-z0-9+.-]*://[^/]*#i', '', $normalized) ?? $normalized;

        // Abfrageteil und Sprungmarke: Fassungsnummern zum Umgehen des
        // Zwischenspeichers (`?v=1699…`) stehen genau dort.
        $normalized = preg_replace('/[?#].*$/', '', $normalized) ?? $normalized;

        // Der Bauteil vor dem ersten bekannten Quellverzeichnis ist die
        // Eigenheit des Rechners, auf dem gebaut wurde (`/home/anna/projekt`,
        // `C:/build/42`, `/var/task`).
        //
        // **Genau eine Ersetzung**, und das ist hier keine Feinheit: ohne die
        // Begrenzung sucht `preg_replace` nach der leeren Übereinstimmung am
        // Anfang gleich weiter und findet das *nächste* Quellverzeichnis. Aus
        // `vendor/paket/src/Client.php` — das schon richtig beginnt — würde
        // dann `src/Client.php`, und zwei Bibliotheken mit gleich benannter
        // Datei fielen zusammen.
        //
        // `build` steht bewusst **nicht** in der Liste: es ist weit häufiger
        // das Arbeitsverzeichnis eines Bauservers als ein Quellverzeichnis, und
        // als Marke würde es genau den Pfad stehen lassen, den es zu entfernen
        // gilt.
        $normalized = preg_replace(
            '#^.*?(?=(?:^|/)(?:src|app|lib|tests?|vendor|node_modules|site-packages|dist)/)#i',
            '',
            $normalized,
            1,
        ) ?? $normalized;

        // Fassungsnummern in Pfaden von Abhängigkeiten (`…/paket/2.7.1/…`).
        $normalized = preg_replace('#/v?\d+\.\d+(?:\.\d+)*(?=/)#', '/<version>', $normalized) ?? $normalized;

        // Der Prüfsummen-Anteil gebündelter Dateien, in beiden üblichen
        // Schreibweisen (`app.4f3a2b1c.js`, `app-4f3a2b1c.js`).
        $normalized = preg_replace(
            '/[.-](?=[0-9a-f]*\d)[0-9a-f]{6,}(?=\.[a-z0-9]+$)/i',
            '.<hash>',
            $normalized,
        ) ?? $normalized;

        $normalized = ltrim($normalized, '/');

        return $normalized === '' ? null : $normalized;
    }
}
