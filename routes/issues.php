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

use App\Http\Controllers\IssueActionController;
use App\Http\Controllers\IssueController;
use App\Http\Controllers\IssueDetailController;
use App\Http\Controllers\IssueTagController;
use App\Http\Controllers\TagController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('fehler', IssueController::class)->name('issues.index');

    // Die Zustandsaktionen (S6) — eine Adresse für einen Fehler wie für
    // zwölftausend. Sie steht **neben** der Liste und nicht unter einem
    // Eintrag: eine Sammelaktion meint keine einzelne Kennung, sondern die
    // Auswahl, und ein Pfad `fehler/{issue}/aktion` müsste für sie eine
    // Kennung erfinden. Welche Einträge gemeint sind, steht im Rumpf — samt
    // der Filterfelder, mit denen die Liste gebaut wurde.
    Route::post('fehler/aktionen', [IssueActionController::class, 'store'])
        ->name('issues.actions.store');
    Route::post('fehler/aktionen/rueckgaengig', [IssueActionController::class, 'undo'])
        ->name('issues.actions.undo');

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
