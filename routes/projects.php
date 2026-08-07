<?php

/*
|--------------------------------------------------------------------------
| Projekte
|--------------------------------------------------------------------------
|
| Included at the end of routes/web.php. Wie bei den Organisationen steckt die
| Rechteprüfung in den Policies (App\Policies), nicht in einer Middleware.
|
| Die Projekt-Routen liegen unterhalb der Organisation, weil der Slug nur dort
| eindeutig ist; `scopeBindings` sorgt dafür, dass ein Projekt nur über seine
| eigene Organisation erreichbar ist.
|
*/

use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectTeamController;
use App\Http\Controllers\ProjectTokenController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    // Liste der aktiven Organisation — der Einstieg aus der Navigation.
    Route::get('projekte', [ProjectController::class, 'index'])
        ->name('projects.index');

    Route::prefix('organisationen/{organization}/projekte')
        ->scopeBindings()
        ->group(function () {
            Route::post('/', [ProjectController::class, 'store'])
                ->name('projects.store');

            Route::get('{project}', [ProjectController::class, 'show'])
                ->name('projects.show');
            Route::patch('{project}', [ProjectController::class, 'update'])
                ->name('projects.update');
            Route::delete('{project}', [ProjectController::class, 'destroy'])
                ->name('projects.destroy');

            Route::put('{project}/teams', [ProjectTeamController::class, 'update'])
                ->name('projects.teams.update');
            Route::post('{project}/token', [ProjectTokenController::class, 'update'])
                ->name('projects.token.update');
        });
});
