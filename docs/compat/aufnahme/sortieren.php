<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Aufnahmen sortieren
|--------------------------------------------------------------------------
|
| Macht aus dem Mitschnitt unter `rohdaten/` die Vorlagen, gegen die
| `Tests\Feature\Ingest\SdkCompatibilityTest` prüft:
|
|     php docs/compat/aufnahme/sortieren.php
|
|     tests/Fixtures/Compat/aufnahmen.json          — Verzeichnis aller Aufnahmen
|     tests/Fixtures/Compat/<sdk>/<art>.envelope    — der Envelope im Klartext
|
| Einsortiert wird nach dem, was drinsteht, nicht nach dem, was beim Aufnehmen
| gedacht war: das SDK steht in `sentry_client`, die Art im Kopf der Elemente.
| Damit sortiert dasselbe Skript jedes SDK, auch ein später hinzugekommenes, ohne
| dass hier eine Liste gepflegt werden muss.
|
| Von mehreren Aufnahmen derselben Art gewinnt die letzte. Das ist bei
| Sitzungen die entscheidende: SDKs schicken dieselbe Sitzung mehrfach — beim
| Beginn und bei jeder Änderung —, und erst die letzte trägt den Abschluss.
|
| Gelesen wird der einfache Fall: je Element eine Kopfzeile und eine Zeile
| Nutzdaten. Ein Element, dessen Kopf eine `length` nennt, darf laut
| Spezifikation Zeilenumbrüche im Rumpf haben (Anhänge, Aufzeichnungen) — dann
| stimmt die Zählung hier nicht mehr. Für die Einordnung genügt das: die Arten,
| um die es geht, kommen als eine Zeile JSON. Der Klon selbst liest den Envelope
| vollständig, siehe App\Support\Ingest\Envelope.
|
*/

const ROHDATEN = __DIR__.'/rohdaten';
const ZIEL = __DIR__.'/../../../tests/Fixtures/Compat';

/**
 * Kopfzeilen, die nicht in die Vorlage gehören: sie beschreiben nicht die
 * Meldung, sondern die eine Verbindung, über die sie kam. `content-length`
 * stimmt nach dem Wiederverpacken nicht mehr, `host` zeigt auf den
 * Mitschnitt-Server, und was der Client als Antwort entgegennimmt, sagt über
 * seine Meldung nichts.
 */
const KOPFZEILEN_WEG = ['host', 'content-length', 'connection', 'accept', 'accept-encoding'];

/**
 * Nimmt einen Namen wie `sentry.javascript.browser/10.69.0` und macht daraus
 * den Ordnernamen `sentry-browser`.
 */
function sdkOrdner(string $kennung): string
{
    $name = strtolower(explode('/', $kennung)[0]);

    return match (true) {
        str_contains($name, 'javascript.browser') => 'sentry-browser',
        str_contains($name, 'javascript.node') => 'sentry-node',
        str_contains($name, 'javascript') => 'sentry-javascript',
        str_contains($name, 'python') => 'sentry-python',
        // Vor `php`, denn das Laravel-SDK heißt `sentry.php.laravel` und würde
        // sonst unter den Aufnahmen des reinen PHP-SDK landen.
        str_contains($name, 'php.laravel') => 'sentry-laravel',
        str_contains($name, 'php') => 'sentry-php',
        default => preg_replace('/[^a-z0-9]+/', '-', $name) ?: 'unbekannt',
    };
}

/**
 * Die Art der Meldung — aus dem ersten Element, das sich zuordnen lässt.
 *
 * `fehler` und `nachricht` sind beides `event`; unterschieden werden sie an
 * `exception`, denn genau das ist der Unterschied, den der Klon später auch
 * macht (Titel, Gruppierung, Stacktrace).
 */
function art(string $typ, mixed $rumpf): ?string
{
    return match ($typ) {
        'event' => is_array($rumpf) && isset($rumpf['exception']) ? 'fehler' : 'nachricht',
        'transaction' => 'transaktion',
        'session', 'sessions' => 'sitzung',
        'check_in' => 'lebenszeichen',
        default => null,
    };
}

function entpacken(string $rumpf, ?string $kodierung): string
{
    if ($kodierung === null) {
        return $rumpf;
    }

    $entpackt = match (strtolower(trim($kodierung))) {
        'gzip', 'x-gzip' => @gzdecode($rumpf),
        'deflate' => @gzuncompress($rumpf) ?: @gzinflate($rumpf),
        default => $rumpf,
    };

    if ($entpackt === false) {
        fwrite(STDERR, "übersprungen: angekündigte Verpackung {$kodierung} ließ sich nicht öffnen\n");

        exit(1);
    }

    return $entpackt;
}

$aufnahmen = [];

foreach (glob(ROHDATEN.'/*.kopf.json') ?: [] as $kopfDatei) {
    $kopf = json_decode((string) file_get_contents($kopfDatei), true);

    if (! is_array($kopf)) {
        continue;
    }

    $rumpf = (string) file_get_contents(str_replace('.kopf.json', '.rumpf.bin', $kopfDatei));
    $kopfzeilen = is_array($kopf['kopfzeilen'] ?? null) ? $kopf['kopfzeilen'] : [];
    $envelope = entpacken($rumpf, $kopfzeilen['content-encoding'] ?? null);

    // Ein Envelope ist zeilenweise: Kopf, dann je Element eine Kopfzeile und
    // eine Zeile Nutzdaten.
    $zeilen = explode("\n", $envelope);
    $arten = [];

    for ($i = 1; $i < count($zeilen); $i += 2) {
        $elementKopf = json_decode($zeilen[$i], true);

        if (! is_array($elementKopf) || ! is_string($elementKopf['type'] ?? null)) {
            continue;
        }

        $gefunden = art($elementKopf['type'], json_decode($zeilen[$i + 1] ?? '', true));

        if ($gefunden !== null) {
            $arten[$gefunden] = true;
        }
    }

    if ($arten === []) {
        fwrite(STDOUT, 'ohne zuordenbares Element, übersprungen: '.basename($kopfDatei)."\n");

        continue;
    }

    // Mehrere Arten in einer Anfrage kommen vor (Fehler und Transaktion
    // zusammen). Die Vorlagen sollen je eine Art zeigen, deshalb liegt so eine
    // Aufnahme unter dem Namen aller enthaltenen Arten und nicht unter einer
    // davon.
    $art = implode('-und-', array_keys($arten));

    $kennung = null;

    if (preg_match('/sentry_client=([^,&\s]+)/', ($kopf['abfrage'] ?? '').','.($kopfzeilen['x-sentry-auth'] ?? ''), $treffer) === 1) {
        $kennung = urldecode($treffer[1]);
    }

    $kennung ??= $kopfzeilen['user-agent'] ?? 'unbekannt';

    $ordner = sdkOrdner($kennung);

    // Die letzte Aufnahme gewinnt; `glob` liefert nach Namen, und der beginnt
    // mit dem Zeitstempel.
    $aufnahmen[$ordner.'/'.$art] = [
        'sdk' => $ordner,
        'sdk_kennung' => $kennung,
        'art' => $art,
        'datei' => $ordner.'/'.$art.'.envelope',
        'aufgezeichnet_am' => substr(basename($kopfDatei), 0, 8),
        'methode' => $kopf['methode'] ?? 'POST',
        'pfad' => $kopf['pfad'] ?? '/',
        'abfrage' => $kopf['abfrage'] ?? null,
        'kopfzeilen' => array_diff_key($kopfzeilen, array_flip(KOPFZEILEN_WEG)),
        'envelope' => $envelope,
    ];
}

if ($aufnahmen === []) {
    fwrite(STDERR, 'keine Aufnahmen unter '.ROHDATEN." — läuft der Mitschnitt-Server?\n");

    exit(1);
}

ksort($aufnahmen);

$verzeichnis = [];

foreach ($aufnahmen as $aufnahme) {
    $envelope = $aufnahme['envelope'];
    unset($aufnahme['envelope']);

    $datei = ZIEL.'/'.$aufnahme['datei'];

    if (! is_dir(dirname($datei)) && ! mkdir(dirname($datei), recursive: true) && ! is_dir(dirname($datei))) {
        fwrite(STDERR, 'Ordner lässt sich nicht anlegen: '.dirname($datei)."\n");

        exit(1);
    }

    file_put_contents($datei, $envelope);

    $verzeichnis[] = $aufnahme;

    fwrite(STDOUT, sprintf("%-40s %6d Byte  %s\n", $aufnahme['datei'], strlen($envelope), $aufnahme['sdk_kennung']));
}

file_put_contents(
    ZIEL.'/aufnahmen.json',
    json_encode(
        ['aufnahmen' => $verzeichnis],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    )."\n",
);

fwrite(STDOUT, count($verzeichnis).' Aufnahmen in '.ZIEL."/aufnahmen.json verzeichnet\n");
