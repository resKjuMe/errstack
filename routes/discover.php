<?php

/*
|--------------------------------------------------------------------------
| Freie Auswertung (Discover)
|--------------------------------------------------------------------------
|
| Included at the end of routes/web.php.
|
| Alles hier liegt unter `/organisationen/{organisation}/…` (U5): welche
| Organisation gemeint ist, steht in der Adresse und nicht in der zuletzt
| gewählten.
|
| Die Seite, auf der eine Frage selbst zusammengestellt wird — und dieselbe
| Frage als Datei. Der Export ist eine eigene Adresse und kein Parameter der
| Seite: er liefert keine Seite, sondern einen Download, und er soll sich
| verlinken und in ein Lesezeichen legen lassen, ohne dass jemand erst die
| Ansicht öffnet.
|
| Beide Adressen tragen denselben Abfragezustand in der Adresszeile (Quelle,
| Gruppierung, Kennzahlen, Suchbedingung, Sortierung, Zeilenzahl, Schrittweite)
| plus die globale Filterleiste. Das ist die Zusage, an der die ganze Seite
| hängt: ein geteilter Link zeigt beim Empfänger dieselbe Auswertung, und die
| Datei enthält genau das, was daneben auf dem Bildschirm steht.
|
*/

use App\Http\Controllers\DiscoverController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])
    ->prefix('organisationen/{organization}')
    ->group(function () {
        Route::get('auswertung', [DiscoverController::class, 'index'])->name('discover.index');

        Route::get('auswertung/csv', [DiscoverController::class, 'export'])->name('discover.export');
    });
