<?php

/*
|--------------------------------------------------------------------------
| Öffentliche Schnittstelle, Version 0
|--------------------------------------------------------------------------
|
| Eingebunden von routes/api.php und damit unter `/api/0/` erreichbar. Die
| Versionsnummer im Pfad ist dieselbe wie bei Sentry: vorhandene Werkzeuge
| (sentry-cli, SDKs) sprechen ohne Umbau mit dieser Adresse. Eine spätere
| Version 1 käme daneben, ohne die 0 abzuschalten.
|
| Jede Route trägt den Rahmen dieses Schrittes:
|   throttle:api-v0  Ratenbegrenzung je Token, 429 samt `Retry-After`
|   auth:sanctum     Bearer-Token, sonst 401
|   api.token        Token, Organisation und Konto an der Anfrage bereitstellen
|   api.organization Slug in der Adresse muss die Organisation des Tokens sein
|   scope:…          nötiger Geltungsbereich, sonst 403
|
| Reihenfolge ist Absicht: die Ratenbegrenzung steht vor der Anmeldung, damit
| auch das Durchprobieren von Tokens gedrosselt wird.
|
*/

use App\Http\Controllers\Api\V0\OrganizationController;
use App\Http\Controllers\Api\V0\ProjectController;
use App\Http\Controllers\Api\V0\RootController;
use Illuminate\Support\Facades\Route;

Route::prefix((string) config('api.version'))
    ->name('api.v0.')
    ->middleware(['throttle:api-v0', 'auth:sanctum', 'api.token', 'api.organization'])
    ->group(function () {
        // Auskunft über Version und Token — ohne eigenen Geltungsbereich, denn
        // sie verrät nur, was der Aufrufer selbst mitgebracht hat.
        Route::get('/', [RootController::class, 'show'])->name('root');

        Route::get('organizations', [OrganizationController::class, 'index'])
            ->middleware('scope:org:read')
            ->name('organizations.index');

        Route::get('organizations/{organization}', [OrganizationController::class, 'show'])
            ->middleware('scope:org:read')
            ->name('organizations.show');

        Route::patch('organizations/{organization}', [OrganizationController::class, 'update'])
            ->middleware('scope:org:write')
            ->name('organizations.update');

        // `scopeBindings`: der Projekt-Slug ist nur innerhalb der Organisation
        // eindeutig, ein Projekt darf also nur über seine eigene aufgelöst werden.
        Route::prefix('organizations/{organization}/projects')
            ->name('projects.')
            ->scopeBindings()
            ->group(function () {
                Route::get('/', [ProjectController::class, 'index'])
                    ->middleware('scope:project:read')
                    ->name('index');

                Route::get('{project}', [ProjectController::class, 'show'])
                    ->middleware('scope:project:read')
                    ->name('show');

                Route::patch('{project}', [ProjectController::class, 'update'])
                    ->middleware('scope:project:write')
                    ->name('update');
            });
    });
