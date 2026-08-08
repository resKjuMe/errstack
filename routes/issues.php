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
| erst in der Auswahl. Für die Detailseite gilt das nicht: sie wird über eine
| Kennung aufgerufen, nicht über eine Auswahl, und prüft deshalb ausdrücklich
| (App\Policies\IssuePolicy).
|
*/

use App\Http\Controllers\IssueController;
use App\Http\Controllers\IssueDetailController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('fehler', IssueController::class)->name('issues.index');

    // Die Detailseite steht unter dem Fehler, die einzelne Meldung darunter:
    // ohne Meldung in der Adresszeile zeigt die Seite die neueste. So ist „der
    // Fehler" verlinkbar, ohne dass der Link auf ein Ereignis zeigt, das morgen
    // nicht mehr das neueste ist — und ein Link auf **diese** eine Meldung ist
    // trotzdem möglich.
    Route::get('fehler/{issue}', [IssueDetailController::class, 'show'])->name('issues.show');
    Route::get('fehler/{issue}/ereignisse/{event}', [IssueDetailController::class, 'show'])
        ->name('issues.events.show');
    Route::get('fehler/{issue}/ereignisse/{event}/rohdaten', [IssueDetailController::class, 'raw'])
        ->name('issues.events.raw');
});
