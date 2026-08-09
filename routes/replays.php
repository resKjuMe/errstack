<?php

/*
|--------------------------------------------------------------------------
| Sitzungs-Aufzeichnungen
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
|
| Die Bilddaten haben eine eigene Adresse und stecken nicht in der Seite. Das ist
| keine Aufteilung um der Sauberkeit willen, sondern die einzige Form, in der
| eine Aufzeichnung ausgeliefert werden kann: sie wiegt Megabyte, sie wird als
| Datenstrom geschrieben, und der Abspieler holt sie, nachdem die Seite steht.
|
| Der Weg von einer Fehlermeldung zu ihrer Aufzeichnung ist eine eigene Route und
| keine Abfrage der aufrufenden Seite — dieselbe Aufteilung wie bei den Profilen
| (M4). Die Fehlerdetailseite hat die Meldung, die sie ohnehin anzeigt; ob es
| dazu eine Aufzeichnung gibt, ist unsere Sache. Sie antwortet auch dann
| sinnvoll, wenn es keine gibt, damit ein Link nicht davon abhängt, ob die
| Aufnahme gerade lief.
|
*/

use App\Http\Controllers\ReplayController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])
    ->prefix('organisationen/{organization}')
    ->group(function () {
        Route::get('aufzeichnungen', [ReplayController::class, 'index'])
            ->name('replays.index');

        Route::get('aufzeichnungen/{replay}', [ReplayController::class, 'show'])
            ->name('replays.show');

        Route::get('aufzeichnungen/{replay}/daten', [ReplayController::class, 'data'])
            ->name('replays.data');

        // Unter den Fehler-Routen und nicht unter „aufzeichnungen": die Adresse
        // beschreibt, woher man kommt, und sie ist damit dieselbe Form wie die
        // Rohdaten einer Meldung (`fehler/{issue}/ereignisse/{event}/rohdaten`).
        Route::get('fehler/{issue}/ereignisse/{event}/aufzeichnung', [ReplayController::class, 'event'])
            ->name('replays.event');
    });
