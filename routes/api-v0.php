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
use App\Http\Controllers\Api\V0\ReleaseCommitController;
use App\Http\Controllers\Api\V0\ReleaseController;
use App\Http\Controllers\Api\V0\RepositoryController;
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

        // Verbundene Repositories (R2). An der Organisation und nicht am
        // Projekt: dasselbe Repository versorgt in aller Regel mehrere
        // Projekte, und der Bezug zu einem entsteht über die Auslieferung, in
        // der seine Commits stecken. `repos` und nicht `repositories`, weil
        // vorhandene Werkzeuge diese Adresse ansprechen.
        Route::get('organizations/{organization}/repos', [RepositoryController::class, 'index'])
            ->middleware('scope:org:read')
            ->name('repositories.index');

        Route::post('organizations/{organization}/repos', [RepositoryController::class, 'store'])
            ->middleware('scope:org:write')
            ->name('repositories.store');

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

                // Ausgelieferte Versionen. Sie entstehen von selbst aus den
                // Meldungen; dieser Weg ist für den umgekehrten Fall da — beim
                // Ausliefern Bescheid geben, bevor der erste Fehler eintrifft.
                Route::get('{project}/releases', [ReleaseController::class, 'index'])
                    ->middleware('scope:project:read')
                    ->name('releases.index');

                Route::post('{project}/releases', [ReleaseController::class, 'store'])
                    ->middleware('scope:project:write')
                    ->name('releases.store');

                // Die Versionsangabe steht roh in der Adresse und ist kein
                // Modell-Parameter: sie ist nur innerhalb ihres Projekts
                // eindeutig, und `scopeBindings` fände dafür keine Beziehung mit
                // passendem Namen. Der Ausdruck lässt alles außer dem
                // Schrägstrich zu — Versionen wie `mein-dienst@1.2.3` sollen
                // sich ohne Umkodieren aufrufen lassen.
                Route::get('{project}/releases/{version}', [ReleaseController::class, 'show'])
                    ->where('version', '[^/]+')
                    ->middleware('scope:project:read')
                    ->name('releases.show');

                // Was in einer Auslieferung steckt (R2). Der Weg für eine
                // Bauumgebung ohne Anbindung: sie kennt die Commits ihres
                // Bereichs ohnehin und übergibt sie beim Ausliefern. Dieselbe
                // Liste lässt sich auch gleich beim Ankündigen der Version
                // mitschicken — dieser Endpunkt ist der Weg, sie später zu
                // ändern.
                Route::get('{project}/releases/{version}/commits', [ReleaseCommitController::class, 'index'])
                    ->where('version', '[^/]+')
                    ->middleware('scope:project:read')
                    ->name('releases.commits.index');

                Route::post('{project}/releases/{version}/commits', [ReleaseCommitController::class, 'store'])
                    ->where('version', '[^/]+')
                    ->middleware('scope:project:write')
                    ->name('releases.commits.store');
            });
    });
