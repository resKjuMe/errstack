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
use App\Http\Controllers\IssueTagController;
use App\Http\Controllers\TagController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('fehler', IssueController::class)->name('issues.index');

    // Die Merkmale eines Fehlers (S3). Sie hängen am Eintrag und stehen deshalb
    // unter ihm — anders als die Liste, die keinen einzelnen Eintrag meint.
    Route::get('fehler/{issue}/merkmale', [IssueTagController::class, 'index'])
        ->name('issues.tags.index');
    Route::get('fehler/{issue}/merkmale/{key}', [IssueTagController::class, 'show'])
        ->name('issues.tags.show');

    // Dieselbe Auswertung über die gewählten Projekte. Sie steht wie die
    // Fehlerliste nicht unter einem Projekt: welche gemeint sind, sagt die
    // Filterleiste.
    Route::get('merkmale', [TagController::class, 'index'])->name('tags.index');
    Route::get('merkmale/{key}', [TagController::class, 'show'])->name('tags.show');
});
