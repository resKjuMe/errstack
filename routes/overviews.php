<?php

/*
|--------------------------------------------------------------------------
| Übersichtsseiten (D5)
|--------------------------------------------------------------------------
|
| Included aus routes/web.php.
|
| Die drei Einstiegsseiten — Organisation, Projekt, Team. Sie stehen zusammen,
| weil sie dieselbe Bauart haben und sich nur im Gegenstand unterscheiden: die
| Seite liefert das Raster, jede Kachel holt ihre Zahlen über eine eigene
| Adresse.
|
| **Die Daten der Kacheln haben eine eigene Adresse.** Fünf Kacheln sind fünf
| Anfragen, die der Browser nebeneinander stellt, und nicht eine, die fünfmal
| hintereinander rechnet — dieselbe Entscheidung wie bei den Dashboards (D4).
| Sie tragen denselben Filterzustand in der Adresszeile wie die Seite: Projekt,
| Umgebung, Zeitraum, Zeitzone.
|
| **`{panel}` ist auf die bekannten Kacheln festgelegt.** Eine unbekannte Kachel
| ist eine unbekannte Adresse (404) und keine leere Antwort: eine Kachel, die
| stillschweigend nichts zeigt, sieht aus wie eine, in der nichts passiert ist.
|
| Nicht zu verwechseln mit routes/dashboards.php: dort stehen die frei
| zusammengestellten Sammlungen von Kacheln, hier die festen Einstiegsseiten.
|
*/

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProjectOverviewController;
use App\Http\Controllers\TeamOverviewController;
use App\Support\Overviews\OrganizationOverview;
use App\Support\Overviews\ProjectOverview;
use App\Support\Overviews\TeamOverview;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])
    ->prefix('organisationen/{organization}')
    ->group(function () {
        // Die Übersicht der Organisation liegt unter `uebersicht` und nicht
        // direkt unter der Organisation: dort steht schon deren Stammdatenseite
        // (`organizations.show`).
        Route::get('uebersicht', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('uebersicht/kacheln/{panel}', [DashboardController::class, 'panel'])
            ->whereIn('panel', OrganizationOverview::PANELS)
            ->name('dashboard.panel');

        Route::scopeBindings()->group(function () {
            Route::get('projekte/{project}/uebersicht', [ProjectOverviewController::class, 'index'])
                ->name('projects.overview');
            Route::get('projekte/{project}/uebersicht/kacheln/{panel}', [ProjectOverviewController::class, 'panel'])
                ->whereIn('panel', ProjectOverview::PANELS)
                ->name('projects.overview.panel');

            Route::get('teams/{team}/uebersicht', [TeamOverviewController::class, 'index'])
                ->name('teams.overview');
            Route::get('teams/{team}/uebersicht/kacheln/{panel}', [TeamOverviewController::class, 'panel'])
                ->whereIn('panel', TeamOverview::PANELS)
                ->name('teams.overview.panel');
        });
    });
