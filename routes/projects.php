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

use App\Http\Controllers\EnvironmentController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectKeyController;
use App\Http\Controllers\ProjectTeamController;
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

            // Umgebungen. Nur die Sichtbarkeit ist einstellbar — die Einträge
            // selbst entstehen aus den eingehenden Meldungen.
            Route::patch('{project}/umgebungen/{environment}', [EnvironmentController::class, 'update'])
                ->name('projects.environments.update');

            // Client-Schlüssel. Sie hängen am Projekt, weshalb `scopeBindings`
            // auch hier greift: ein Schlüssel ist nur über sein eigenes
            // Projekt erreichbar.
            Route::get('{project}/schluessel', [ProjectKeyController::class, 'index'])
                ->name('projects.keys.index');
            Route::post('{project}/schluessel', [ProjectKeyController::class, 'store'])
                ->name('projects.keys.store');
            Route::patch('{project}/schluessel/{key}', [ProjectKeyController::class, 'update'])
                ->name('projects.keys.update');
            Route::post('{project}/schluessel/{key}/zustand', [ProjectKeyController::class, 'toggle'])
                ->name('projects.keys.toggle');
            Route::post('{project}/schluessel/{key}/rotation', [ProjectKeyController::class, 'rotate'])
                ->name('projects.keys.rotate');
            Route::delete('{project}/schluessel/{key}', [ProjectKeyController::class, 'destroy'])
                ->name('projects.keys.destroy');
        });
});
