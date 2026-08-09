<?php

/*
|--------------------------------------------------------------------------
| Alarm-Übersicht eines Projekts
|--------------------------------------------------------------------------
|
| Included at the end of routes/web.php. Wie bei den Organisationen steckt die
| Rechteprüfung in den Policies (App\Policies), nicht in einer Middleware.
|
| Was am Projekt eingerichtet wird — Stammdaten, Schlüssel, Umgebungen,
| Regellisten, Alarme, Datenschutz —, liegt seit U6 im Einstellungsbereich:
| routes/settings/projects.php.
|
| Die Alarm-Übersicht (A4) bleibt hier: auf ihr wird nachgesehen und nicht
| eingerichtet. Wer nach einer Störung nachsieht, will nicht erst wissen, ob es
| ein Schwellwert-Alarm oder eine Fehler-Regel war — und er sucht das nicht unter
| „Einstellungen".
|
| Ansehen darf jedes Mitglied, aus demselben Grund wie bei den
| Einstellungsseiten.
|
*/

use App\Http\Controllers\AlertOverviewController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])
    ->prefix('organisationen/{organization}/projekte')
    ->scopeBindings()
    ->group(function () {
        Route::get('{project}/alarmuebersicht', [AlertOverviewController::class, 'index'])
            ->name('projects.alert-overview.index');
        Route::get('{project}/alarmuebersicht/alarme/{metric_alert}', [AlertOverviewController::class, 'metricAlert'])
            ->name('projects.alert-overview.metric');
        Route::get('{project}/alarmuebersicht/regeln/{issue_alert_rule}', [AlertOverviewController::class, 'issueAlertRule'])
            ->name('projects.alert-overview.issue');
    });
