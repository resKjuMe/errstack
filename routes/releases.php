<?php

/*
|--------------------------------------------------------------------------
| Versionen (Releases)
|--------------------------------------------------------------------------
|
| Included at the end of routes/web.php.
|
| Alles hier liegt unter `/organisationen/{organisation}/…` (U5): welche
| Organisation gemeint ist, steht in der Adresse und nicht in der zuletzt
| gewählten. Aufgelöst und auf Mitgliedschaft geprüft wird sie in
| App\Http\Middleware\ResolveOrganization.
|
| Wie die Fehlerliste hängt die Versionsliste nicht an einem Projekt in der
| Adresszeile: welche Projekte gemeint sind, steht in der globalen Filterleiste
| und damit ohnehin dort.
|
| Die Rechteprüfung steckt nicht in einer Middleware, sondern im Filter: er löst
| die wählbaren Projekte über die Mitgliedschaft des Betrachters auf
| (App\Support\Filters\GlobalFilter) — was er nicht sehen darf, steht gar nicht
| erst in der Auswahl.
|
*/

use App\Http\Controllers\ReleaseController;
use App\Http\Controllers\ReleaseDetailController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])
    ->prefix('organisationen/{organization}')
    ->group(function () {
        Route::get('versionen', ReleaseController::class)->name('releases.index');

        // Die Detailseite hängt an der Kennung und nicht an Projekt und
        // Versionsangabe: die Angabe ist nur innerhalb ihres Projekts eindeutig,
        // und beides in der Adresse hieße, dieselbe Sache zweimal zu nennen — mit
        // der Möglichkeit, dass sie nicht zusammenpassen. Anders als die Liste hat
        // diese Seite damit keine Vorauswahl über die Filterleiste; die
        // Rechteprüfung steht deshalb im Controller.
        Route::get('versionen/{release}', ReleaseDetailController::class)->name('releases.show');
    });
