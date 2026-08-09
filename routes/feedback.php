<?php

/*
|--------------------------------------------------------------------------
| Nutzer-Rückmeldungen
|--------------------------------------------------------------------------
|
| Included at the end of routes/web.php.
|
| Alles hier liegt unter `/organisationen/{organisation}/…` (U5): welche
| Organisation gemeint ist, steht in der Adresse und nicht in der zuletzt
| gewählten. Aufgelöst und auf Mitgliedschaft geprüft wird sie in
| App\Http\Middleware\ResolveOrganization.
|
| Wie die Fehlerliste hängt die Liste nicht an einem Projekt in der Adresszeile:
| welche Projekte gemeint sind, sagt die globale Filterleiste. Die Handlungen an
| einer einzelnen Rückmeldung werden dagegen über ihre Kennung aufgerufen und
| prüfen deshalb ausdrücklich (App\Policies\UserReportPolicy).
|
| Wo eine Rückmeldung **herkommt**, steht nicht hier, sondern in routes/ingest.php:
| sie kommt über dieselbe Datenaufnahme herein wie alles andere.
|
*/

use App\Http\Controllers\UserReportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])
    ->prefix('organisationen/{organization}')
    ->group(function () {
        Route::get('rueckmeldungen', [UserReportController::class, 'index'])->name('feedback.index');

        Route::patch('rueckmeldungen/{userReport}/stand', [UserReportController::class, 'status'])
            ->name('feedback.status');

        Route::patch('rueckmeldungen/{userReport}/zuweisung', [UserReportController::class, 'assignment'])
            ->name('feedback.assignment');
    });
