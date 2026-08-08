<?php

/*
|--------------------------------------------------------------------------
| Profiling
|--------------------------------------------------------------------------
|
| Included at the end of routes/web.php.
|
| Alles hier liegt unter `/organisationen/{organisation}/…` (U5): welche
| Organisation gemeint ist, steht in der Adresse und nicht in der zuletzt
| gewählten. Aufgelöst und auf Mitgliedschaft geprüft wird sie in
| App\Http\Middleware\ResolveOrganization.
|
| Die Übersicht hängt wie alle Auswertungsseiten nicht an einem Projekt in der
| Adresszeile: welche Projekte gemeint sind, steht in der globalen Filterleiste.
| Die Rechteprüfung steckt damit im Filter — was jemand nicht sehen darf, steht
| gar nicht erst in der Auswahl.
|
| Die beiden Wege von einer Messung und von einem Ablauf zum Profil sind eigene
| Routen und keine Abfrage der aufrufenden Seite. Der Grund ist die Reihenfolge,
| in der diese Anwendung entsteht: die Detailseite einer Transaktion (PF3) und
| die Trace-Ansicht (PF4) sollen hierher verlinken können, ohne etwas über
| Profile zu wissen — sie haben die Kennung, die sie ohnehin anzeigen, und der
| Rest ist unsere Sache. Beide Routen antworten auch dann sinnvoll, wenn es kein
| Profil gibt, damit ein Link nicht davon abhängt, ob die Stichprobe gerade
| zugeschlagen hat.
|
*/

use App\Http\Controllers\ProfilingController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])
    ->prefix('organisationen/{organization}')
    ->group(function () {
        Route::get('leistung/profile', [ProfilingController::class, 'index'])
            ->name('profiling.index');

        Route::get('leistung/profile/{profile}', [ProfilingController::class, 'show'])
            ->name('profiling.show');

        Route::get('leistung/transaktionen/{transaction}/profil', [ProfilingController::class, 'transaction'])
            ->name('profiling.transaction');

        // Die Trace-Kennung ist keine Zeile in einer Tabelle, sondern 32
        // Hex-Zeichen, die mehrere Projekte gemeinsam haben — deshalb kein
        // Modell-Binding, sondern eine geprüfte Zeichenkette.
        Route::get('leistung/traces/{trace}/profil', [ProfilingController::class, 'trace'])
            ->where('trace', '[0-9a-fA-F]{32}')
            ->name('profiling.trace');
    });
