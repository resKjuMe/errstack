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
| **Die gespeicherten Auswertungen haben keine eigene Seite.** Sie werden dort
| verwaltet, wo sie entstehen, und alle Aufrufe kehren dorthin zurück; geöffnet
| wird eine gespeicherte Auswertung über `auswertung` selbst, weil ihr ganzer
| Zustand ohnehin in die Adresse geht. Die einzige Ausnahme ist das Übernehmen
| als Kachel: es endet auf dem Dashboard, weil die Kachel dort gelandet ist.
|
*/

use App\Http\Controllers\DiscoverController;
use App\Http\Controllers\SavedQueryController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])
    ->prefix('organisationen/{organization}')
    ->group(function () {
        Route::get('auswertung', [DiscoverController::class, 'index'])->name('discover.index');

        Route::get('auswertung/csv', [DiscoverController::class, 'export'])->name('discover.export');

        // Der Platzhalter heißt `savedQuery` und nicht `query`: Letzteres ist am
        // Request bereits der Zugriff auf die Adresszeile, und ein gleichnamiger
        // Parameter wäre an jeder Stelle, die ihn liest, eine Verwechslung, die
        // erst zur Laufzeit auffiele.
        Route::post('auswertung/gespeichert', [SavedQueryController::class, 'store'])
            ->name('discover.saved.store');

        Route::patch('auswertung/gespeichert/{savedQuery}', [SavedQueryController::class, 'update'])
            ->name('discover.saved.update');

        Route::delete('auswertung/gespeichert/{savedQuery}', [SavedQueryController::class, 'destroy'])
            ->name('discover.saved.destroy');

        Route::post('auswertung/gespeichert/{savedQuery}/duplizieren', [SavedQueryController::class, 'duplicate'])
            ->name('discover.saved.duplicate');

        Route::post('auswertung/gespeichert/{savedQuery}/kachel', [SavedQueryController::class, 'widget'])
            ->name('discover.saved.widget');
    });
