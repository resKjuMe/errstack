<?php

/*
|--------------------------------------------------------------------------
| Dashboards
|--------------------------------------------------------------------------
|
| Included at the end of routes/web.php.
|
| Alles hier liegt unter `/organisationen/{organisation}/…` (U5): welche
| Organisation gemeint ist, steht in der Adresse und nicht in der zuletzt
| gewählten.
|
| Frei zusammengestellte Sammlungen von Kacheln — nicht zu verwechseln mit der
| einen Übersichtsseite (`uebersicht`, Route `dashboard`), die den Einstieg in
| die Organisation bildet.
|
| **Die Daten der Kacheln haben eine eigene Adresse.** Die Seite liefert das
| Raster, jede Kachel holt ihre Zahlen danach selbst — zwanzig Kacheln sind
| zwanzig Anfragen, die der Browser nebeneinander stellt, und nicht eine, die
| zwanzigmal hintereinander rechnet. Sie trägt denselben Filterzustand in der
| Adresszeile wie die Seite: Projekt, Umgebung, Zeitraum, Zeitzone.
|
| **Die Anordnung ist ein eigener Aufruf.** Verschieben ist keine Bearbeitung
| einer Kachel, sondern eine Bewegung des ganzen Rasters — und sie wird als eine
| gespeichert, damit die Anordnung im Browser und die in der Datenbank nicht
| halb auseinanderfallen können.
|
*/

use App\Http\Controllers\DashboardsController;
use App\Http\Controllers\DashboardWidgetController;
use App\Http\Controllers\DashboardWidgetDataController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])
    ->prefix('organisationen/{organization}')
    ->group(function () {
        Route::get('dashboards', [DashboardsController::class, 'index'])->name('dashboards.index');
        Route::post('dashboards', [DashboardsController::class, 'store'])->name('dashboards.store');

        Route::get('dashboards/{dashboard}', [DashboardsController::class, 'show'])->name('dashboards.show');
        Route::patch('dashboards/{dashboard}', [DashboardsController::class, 'update'])->name('dashboards.update');
        Route::delete('dashboards/{dashboard}', [DashboardsController::class, 'destroy'])->name('dashboards.destroy');
        Route::post('dashboards/{dashboard}/duplizieren', [DashboardsController::class, 'duplicate'])->name('dashboards.duplicate');

        Route::post('dashboards/{dashboard}/kacheln', [DashboardWidgetController::class, 'store'])->name('dashboards.widgets.store');
        Route::patch('dashboards/{dashboard}/anordnung', [DashboardWidgetController::class, 'layout'])->name('dashboards.widgets.layout');
        Route::patch('dashboards/{dashboard}/kacheln/{widget}', [DashboardWidgetController::class, 'update'])->name('dashboards.widgets.update');
        Route::delete('dashboards/{dashboard}/kacheln/{widget}', [DashboardWidgetController::class, 'destroy'])->name('dashboards.widgets.destroy');

        Route::get('dashboards/{dashboard}/kacheln/{widget}/daten', DashboardWidgetDataController::class)
            ->name('dashboards.widgets.data');
    });
