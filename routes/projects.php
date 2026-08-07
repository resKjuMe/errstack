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

use App\Http\Controllers\CronMonitorController;
use App\Http\Controllers\EnvironmentController;
use App\Http\Controllers\FingerprintRuleController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectKeyController;
use App\Http\Controllers\ProjectPrivacyController;
use App\Http\Controllers\ProjectTeamController;
use App\Http\Controllers\ScrubRuleController;
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

            // Überwachte Cronjobs. Der Parametername ist `cron_monitor` und
            // nicht `cronjob`, weil `scopeBindings` daraus die Beziehung am
            // Projekt ableitet (`cronMonitors()`) — mit einem freieren Namen
            // fände es sie nicht und der Monitor wäre über jedes Projekt
            // erreichbar.
            Route::get('{project}/cronjobs', [CronMonitorController::class, 'index'])
                ->name('projects.crons.index');
            Route::post('{project}/cronjobs', [CronMonitorController::class, 'store'])
                ->name('projects.crons.store');
            Route::patch('{project}/cronjobs/{cron_monitor}', [CronMonitorController::class, 'update'])
                ->name('projects.crons.update');
            Route::post('{project}/cronjobs/{cron_monitor}/zustand', [CronMonitorController::class, 'toggle'])
                ->name('projects.crons.toggle');
            Route::delete('{project}/cronjobs/{cron_monitor}', [CronMonitorController::class, 'destroy'])
                ->name('projects.crons.destroy');

            // Fingerprint-Regeln der Gruppierung. Der Parametername ist
            // `fingerprint_rule` und nicht `regel`, weil `scopeBindings` daraus
            // die Beziehung am Projekt ableitet (`fingerprintRules()`) — mit
            // einem freieren Namen fände es sie nicht, und eine Regel wäre über
            // jedes Projekt erreichbar.
            Route::get('{project}/gruppierung', [FingerprintRuleController::class, 'index'])
                ->name('projects.grouping.index');
            Route::post('{project}/gruppierung', [FingerprintRuleController::class, 'store'])
                ->name('projects.grouping.store');
            Route::patch('{project}/gruppierung/{fingerprint_rule}', [FingerprintRuleController::class, 'update'])
                ->name('projects.grouping.update');
            Route::post('{project}/gruppierung/{fingerprint_rule}/zustand', [FingerprintRuleController::class, 'toggle'])
                ->name('projects.grouping.toggle');
            Route::delete('{project}/gruppierung/{fingerprint_rule}', [FingerprintRuleController::class, 'destroy'])
                ->name('projects.grouping.destroy');

            // Datenschutz. Die Seite darf jedes Mitglied ansehen — was von einer
            // Meldung übrig bleibt, muss jeder wissen, der mit den Daten
            // arbeitet; geändert wird sie von der Verwaltung.
            Route::get('{project}/datenschutz', [ProjectPrivacyController::class, 'index'])
                ->name('projects.privacy.index');
            Route::patch('{project}/datenschutz', [ProjectPrivacyController::class, 'update'])
                ->name('projects.privacy.update');

            // Die Vorschau ändert nichts, ist aber ein POST: das Beispielereignis
            // ist ein ganzer JSON-Rumpf und hat in einer Adresszeile nichts zu
            // suchen.
            Route::post('{project}/datenschutz/vorschau', [ProjectPrivacyController::class, 'preview'])
                ->name('projects.privacy.preview');

            // Angelegt wird die Regel hier, geändert und gelöscht über
            // `scrub-rules.*` — siehe ScrubRuleController.
            Route::post('{project}/datenschutz/regeln', [ScrubRuleController::class, 'storeForProject'])
                ->name('projects.privacy.rules.store');
        });
});
