<?php

namespace App\Support\SelfMonitoring;

/**
 * Welche Auslieferung gerade läuft.
 *
 * Die Angabe entscheidet, ob ein Fehler einer Version zugeordnet werden kann
 * (R1) — ohne sie steht in der Übersicht „erste Version: unbekannt", und die
 * Frage „seit wann ist das kaputt" bleibt offen.
 *
 * Zwei Quellen, in dieser Reihenfolge:
 *
 *   1. `SENTRY_RELEASE` — was die Auslieferung ausdrücklich sagt.
 *   2. eine Datei `VERSION` im Wurzelverzeichnis.
 *
 * Die Datei ist der Weg für eine Auslieferung ohne eigene Umgebungsvariablen:
 * ein `git rev-parse --short HEAD > VERSION` im Deploy-Skript genügt, und die
 * Angabe wandert mit dem ausgelieferten Stand statt in einer Konfiguration zu
 * veralten. Sie wird **nicht** eingecheckt (siehe .gitignore) — eine
 * mitgelieferte Versionsdatei wäre ab dem nächsten Commit falsch.
 *
 * Der Aufruf von `git` zur Laufzeit wäre die dritte denkbare Quelle und ist
 * bewusst keine: auf dem Server liegt in aller Regel kein Arbeitsverzeichnis,
 * und ein Prozessaufruf bei jedem Hochfahren der Anwendung ist ein hoher Preis
 * für eine Angabe, die sich zwischen zwei Auslieferungen nie ändert.
 */
final class DeployedVersion
{
    /**
     * `null`, wenn keine Quelle etwas hergibt — dann meldet das SDK die
     * Meldungen ohne Versionsangabe, statt eine erfundene mitzuschicken.
     */
    public static function resolve(?string $fromEnvironment, string $basePath): ?string
    {
        $fromEnvironment = trim((string) $fromEnvironment);

        if ($fromEnvironment !== '') {
            return $fromEnvironment;
        }

        $file = rtrim($basePath, '/\\').DIRECTORY_SEPARATOR.'VERSION';

        if (! is_file($file) || ! is_readable($file)) {
            return null;
        }

        $contents = @file_get_contents($file);

        if ($contents === false) {
            return null;
        }

        // Die erste Zeile und nichts weiter: ein `git log` in die Datei
        // umgeleitet soll eine Version ergeben und keinen Absatz.
        $version = trim(strtok($contents, "\n") ?: '');

        return $version === '' ? null : $version;
    }
}
