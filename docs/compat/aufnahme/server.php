<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Mitschnitt-Server
|--------------------------------------------------------------------------
|
| Nimmt die Stelle des Klons ein, während die Beispiele laufen, und schreibt
| jede Anfrage unverändert auf die Platte:
|
|     php -S 127.0.0.1:9911 docs/compat/aufnahme/server.php
|
| Warum nicht gleich gegen den Klon selbst aufzeichnen: der Mitschnitt soll die
| Bytes festhalten, die das SDK über die Leitung schickt — vor jeder Auswertung.
| Ein Endpunkt, der die Meldung schon entpackt und ablegt, kann das nicht
| liefern, ohne dass man ihn dafür umbaut. Umgekehrt gilt: dieser Server prüft
| nichts, er antwortet immer mit Erfolg. Ob der Klon die Meldung wirklich
| versteht, entscheidet der Test gegen die Aufnahme, nicht dieser Server.
|
| Er antwortet dabei genau so, wie Sentry es tut — Antwortform, Kopfzeilen für
| CORS, `OPTIONS` —, denn ein SDK, dem die Antwort nicht gefällt, wiederholt die
| Meldung oder bricht die Sitzung ab, und dann fehlt sie im Mitschnitt.
|
| Ziel der Aufnahme ist `docs/compat/aufnahme/rohdaten/`; von dort sortiert
| `sortieren.php` die Aufnahmen in die Vorlagen unter `tests/Fixtures/Compat/`.
|
*/

const ROHDATEN = __DIR__.'/rohdaten';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: '.($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] ?? '*'));
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Max-Age: 86400');

// Die Vorab-Anfrage des Browsers. Sie trägt keine Nutzdaten und wird nicht
// mitgeschnitten — aber sie muss beantwortet werden, sonst schickt der Browser
// die eigentliche Meldung nie ab.
if ($method === 'OPTIONS') {
    http_response_code(200);

    return;
}

if ($method !== 'POST' && $method !== 'GET') {
    http_response_code(405);

    return;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// Alles außerhalb von `/api/…` ist keine Meldung, sondern das Beispiel selbst:
// die Browser-Seite wird von hier ausgeliefert, damit sie und der Empfänger
// dieselbe Herkunft haben. Sonst bräuchte man einen zweiten Server, nur um eine
// Datei auszuliefern.
if (! str_starts_with($path, '/api/')) {
    $file = realpath(__DIR__.'/..'.$path);
    $root = realpath(__DIR__.'/..');

    if ($file === false || $root === false || ! str_starts_with($file, $root) || ! is_file($file)) {
        http_response_code(404);
        echo 'Nicht gefunden: '.$path;

        return;
    }

    header('Content-Type: '.match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
        'html' => 'text/html; charset=utf-8',
        'js', 'mjs' => 'text/javascript; charset=utf-8',
        'json' => 'application/json',
        'map' => 'application/json',
        default => 'text/plain; charset=utf-8',
    });

    readfile($file);

    return;
}

if (! is_dir(ROHDATEN) && ! mkdir(ROHDATEN, recursive: true) && ! is_dir(ROHDATEN)) {
    http_response_code(500);
    echo 'Mitschnitt-Ordner '.ROHDATEN.' lässt sich nicht anlegen';

    return;
}

$body = file_get_contents('php://input');
$body = $body === false ? '' : $body;

$headers = [];

foreach ($_SERVER as $name => $value) {
    if (str_starts_with($name, 'HTTP_') && is_string($value)) {
        $headers[strtolower(str_replace('_', '-', substr($name, 5)))] = $value;
    }
}

// Die beiden gehören nicht zu `HTTP_*`, sind hier aber die wichtigsten: an
// `content-type` erkennt der Klon den Envelope, an `content-encoding` die
// Verpackung.
foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $from => $to) {
    if (isset($_SERVER[$from]) && is_string($_SERVER[$from]) && $_SERVER[$from] !== '') {
        $headers[$to] = $_SERVER[$from];
    }
}

ksort($headers);

$stamp = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Ymd-His-v');
$name = ROHDATEN.'/'.$stamp.'-'.substr(bin2hex(random_bytes(4)), 0, 6);

file_put_contents($name.'.rumpf.bin', $body);
file_put_contents($name.'.kopf.json', json_encode([
    'methode' => $method,
    'pfad' => $path,
    'abfrage' => parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_QUERY) ?: null,
    'kopfzeilen' => $headers,
    'bytes' => strlen($body),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

// `error_log` statt `STDERR`: der eingebaute Server stellt dem Router-Skript
// keine Standard-Streams bereit, seine Protokollzeilen gehen aber ins
// Fehlerprotokoll — dort steht der Mitschnitt dann neben der Zugriffszeile.
error_log(sprintf(
    'mitgeschnitten: %s %s (%d Byte) -> %s',
    $method,
    $_SERVER['REQUEST_URI'] ?? '/',
    strlen($body),
    basename($name),
));

// Sentrys Antwort auf eine angenommene Meldung: die Nummer des Ereignisses,
// sonst nichts.
header('Content-Type: application/json');
http_response_code(200);
echo json_encode(['id' => bin2hex(random_bytes(16))]);
