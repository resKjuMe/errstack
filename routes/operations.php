<?php

/*
|--------------------------------------------------------------------------
| Betrieb der eigenen Installation
|--------------------------------------------------------------------------
|
| Eingebunden am Ende von routes/web.php.
|
| Zwei Arten von Adressen, und der Unterschied ist der Zugang:
|
| `/health` und `/metrics` sind für Maschinen — Ladeverteiler, Container-
| Verwaltung, Prometheus. Sie stehen **außerhalb** von `auth`, weil die
| Gegenstelle sich nicht anmelden kann: ein Ladeverteiler, der zur Anmeldung
| umgeleitet wird, hält die Installation für gesund, solange die Anmeldeseite
| antwortet. Was das an Auskunft bedeutet, ist an beiden Stellen bedacht —
| `/health` sagt nichts als „geht/geht nicht", `/metrics` ist ausgeliefert aus
| und kann einen Token verlangen.
|
| Die Betriebsansicht ist für Menschen und liegt hinter Anmeldung **und** dem
| Gate `operations`: sie zeigt Zahlen aus dem Inneren und hat Schaltflächen,
| die Jobs erneut starten.
|
| Auch ohne Sitzung: die beiden Maschinen-Adressen liegen bewusst nicht unter
| `/api`, damit sie von der Fehlerbehandlung der Schnittstelle unberührt
| bleiben — `/health` soll seine eigene Antwortform behalten, auch wenn sich an
| der Schnittstelle etwas ändert.
|
*/

use App\Http\Controllers\HealthController;
use App\Http\Controllers\MetricsController;
use App\Http\Controllers\OperationsController;
use Illuminate\Support\Facades\Route;

Route::get('health', HealthController::class)->name('health');

Route::get('metrics', MetricsController::class)->name('metrics');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('betrieb', [OperationsController::class, 'index'])->name('operations.index');

    Route::post('betrieb/jobs/erneut', [OperationsController::class, 'retryJob'])
        ->name('operations.jobs.retry');

    Route::post('betrieb/jobs/alle-erneut', [OperationsController::class, 'retryAllJobs'])
        ->name('operations.jobs.retry-all');

    Route::post('betrieb/jobs/verwerfen', [OperationsController::class, 'forgetJob'])
        ->name('operations.jobs.forget');

    Route::post('betrieb/meldungen/erneut', [OperationsController::class, 'retryPayloads'])
        ->name('operations.payloads.retry');
});
