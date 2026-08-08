<?php

/*
|--------------------------------------------------------------------------
| Versionen (Releases)
|--------------------------------------------------------------------------
|
| Included at the end of routes/web.php.
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
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('versionen', ReleaseController::class)->name('releases.index');
});
