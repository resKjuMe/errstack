<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Beispiel: sentry-php
|--------------------------------------------------------------------------
|
| Schickt einen Fehler, eine Nachricht und eine Transaktion mit dem offiziellen
| PHP-SDK — unverändert, aus dem `vendor/` dieses Projekts (`sentry/sentry`, es
| liegt wegen der Selbstüberwachung ohnehin schon da). Getauscht wird nur die
| DSN:
|
|     # gegen den laufenden Klon
|     SENTRY_DSN="http://<public_key>@localhost:8000/1" php docs/compat/sentry-php/senden.php
|
|     # gegen den Mitschnitt-Server (Aufnahme neu erstellen)
|     php docs/compat/sentry-php/senden.php
|
| Sitzungen fehlen hier, und zwar nicht aus Nachlässigkeit: das PHP-SDK kennt
| keine (Release Health ist in `sentry/sentry` nicht umgesetzt — ein PHP-Prozess
| lebt für eine Anfrage, eine „Sitzung" hätte dort keine Entsprechung). Sie
| kommen aus den Beispielen für Node, Browser und Python.
|
*/

require __DIR__.'/../../../vendor/autoload.php';

use Sentry\Breadcrumb;
use Sentry\SentrySdk;
use Sentry\Severity;
use Sentry\State\Scope;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\TransactionContext;

$dsn = getenv('SENTRY_DSN') ?: 'http://aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa@127.0.0.1:9911/1';

\Sentry\init([
    'dsn' => $dsn,
    'release' => 'compat@1.0.0',
    'environment' => 'compat',
    // Ohne das schickt das SDK die Transaktion nicht ab, sondern verwirft sie
    // als nicht gezogene Stichprobe.
    'traces_sample_rate' => 1.0,
    'server_name' => 'compat-beispiel',
    'attach_stacktrace' => true,
]);

\Sentry\configureScope(function (Scope $scope): void {
    $scope->setUser(['id' => '4711', 'username' => 'kompatibilitaet']);
    $scope->setTag('beispiel', 'sentry-php');
});

\Sentry\addBreadcrumb(new Breadcrumb(
    Breadcrumb::LEVEL_INFO,
    Breadcrumb::TYPE_DEFAULT,
    'beispiel',
    'Rechnung geladen',
    ['rechnung' => 4711],
));

// 1. Eine Nachricht ohne Fehler.
\Sentry\captureMessage('Kompatibilitätsprobe: Nachricht aus sentry-php', Severity::info());
\Sentry\flush();

// 2. Ein echter Fehler mit Stacktrace. Absichtlich zwei Ebenen tief und mit
//    Ursache, damit die Aufnahme eine Kette enthält und nicht nur einen Rahmen.
try {
    (function (): void {
        try {
            throw new InvalidArgumentException('Rechnungsnummer 4711 ist unbekannt');
        } catch (Throwable $ursache) {
            throw new RuntimeException('Rechnung konnte nicht erstellt werden', 500, $ursache);
        }
    })();
} catch (Throwable $fehler) {
    \Sentry\captureException($fehler);
}

\Sentry\flush();

// 3. Eine Transaktion mit zwei Einzelschritten.
$transaction = \Sentry\startTransaction(
    (new TransactionContext)
        ->setName('GET /rechnungen')
        ->setOp('http.server')
);

SentrySdk::getCurrentHub()->setSpan($transaction);

foreach ([['db.sql.query', 'select * from invoices'], ['http.client', 'GET https://zahlungen.example/status']] as [$op, $beschreibung]) {
    $span = $transaction->startChild(
        (new SpanContext)->setOp($op)->setDescription($beschreibung)
    );
    usleep(15_000);
    $span->finish();
}

$transaction->finish();

\Sentry\flush();

fwrite(STDOUT, "sentry-php: Nachricht, Fehler und Transaktion an {$dsn} geschickt\n");
