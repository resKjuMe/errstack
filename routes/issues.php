<?php

/*
|--------------------------------------------------------------------------
| Fehler
|--------------------------------------------------------------------------
|
| Included at the end of routes/web.php.
|
| Die Fehlerliste hängt nicht an einem Projekt in der Adresszeile: welche
| Projekte gemeint sind, steht in der globalen Filterleiste und damit ohnehin in
| der Adresszeile. Zwei Wege, ein Projekt zu wählen, wären einer zu viel.
|
| Die Rechteprüfung steckt nicht in einer Middleware: der Filter löst die
| wählbaren Projekte über die Mitgliedschaft des Betrachters auf
| (App\Support\Filters\GlobalFilter) — was er nicht sehen darf, steht gar nicht
| erst in der Auswahl.
|
*/

use App\Http\Controllers\IssueController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('fehler', IssueController::class)->name('issues.index');
});
