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

use App\Http\Controllers\AlertOverviewController;
use App\Http\Controllers\AlertSnoozeController;
use App\Http\Controllers\CronMonitorController;
use App\Http\Controllers\EnvironmentController;
use App\Http\Controllers\FingerprintRuleController;
use App\Http\Controllers\IssueAlertRuleController;
use App\Http\Controllers\MetricAlertController;
use App\Http\Controllers\OwnershipRuleController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectDigestController;
use App\Http\Controllers\ProjectFilterController;
use App\Http\Controllers\ProjectKeyController;
use App\Http\Controllers\ProjectPerformanceController;
use App\Http\Controllers\ProjectPrivacyController;
use App\Http\Controllers\ProjectSetupController;
use App\Http\Controllers\ProjectTeamController;
use App\Http\Controllers\SamplingRuleController;
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

            // Der Einrichtungs-Assistent. Er zeigt die DSN im Klartext und
            // braucht deshalb dasselbe Recht wie die Schlüssel-Seite. Einen
            // gespeicherten Fortschritt hat er nicht — er ist jederzeit erneut
            // aufrufbar (siehe ProjectSetupController).
            Route::get('{project}/einrichtung', [ProjectSetupController::class, 'index'])
                ->name('projects.setup.index');
            // Woher der Wartebildschirm erfährt, dass die erste Meldung da ist,
            // ohne dass die Seite neu geladen wird.
            Route::get('{project}/einrichtung/stand', [ProjectSetupController::class, 'status'])
                ->name('projects.setup.status');

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

            // Eingangsfilter. Der Parametername ist `inbound_filter_rule` und
            // nicht `eintrag`, weil `scopeBindings` daraus die Beziehung am
            // Projekt ableitet (`inboundFilterRules()`) — mit einem freieren
            // Namen fände es sie nicht, und ein Eintrag wäre über jedes Projekt
            // erreichbar.
            //
            // Ansehen darf jedes Mitglied: wer eine Meldung vermisst, muss
            // nachsehen können, ob ein Filter sie genommen hat.
            Route::get('{project}/filter', [ProjectFilterController::class, 'index'])
                ->name('projects.filters.index');
            Route::patch('{project}/filter', [ProjectFilterController::class, 'update'])
                ->name('projects.filters.update');
            Route::post('{project}/filter/eintraege', [ProjectFilterController::class, 'store'])
                ->name('projects.filters.rules.store');
            Route::patch('{project}/filter/eintraege/{inbound_filter_rule}', [ProjectFilterController::class, 'updateRule'])
                ->name('projects.filters.rules.update');
            Route::post('{project}/filter/eintraege/{inbound_filter_rule}/zustand', [ProjectFilterController::class, 'toggle'])
                ->name('projects.filters.rules.toggle');
            Route::delete('{project}/filter/eintraege/{inbound_filter_rule}', [ProjectFilterController::class, 'destroy'])
                ->name('projects.filters.rules.destroy');

            // Schwellwert-Alarme auf Kennzahlen. Der Parametername ist
            // `metric_alert` — aus demselben Grund wie bei den Fingerprint- und
            // Stichproben-Regeln: `scopeBindings` leitet daraus die Beziehung am
            // Projekt ab (`metricAlerts()`), und mit einem freieren Namen wäre
            // ein Alarm über jedes Projekt erreichbar.
            Route::get('{project}/alarme', [MetricAlertController::class, 'index'])
                ->name('projects.alerts.index');
            Route::post('{project}/alarme', [MetricAlertController::class, 'store'])
                ->name('projects.alerts.store');
            // Die Vorschau ändert nichts, ist aber ein POST: sie bezieht sich
            // auf eine Einstellung, die noch nicht gespeichert ist, und trägt
            // deshalb den ganzen Entwurf im Rumpf.
            Route::post('{project}/alarme/vorschau', [MetricAlertController::class, 'preview'])
                ->name('projects.alerts.preview');
            Route::patch('{project}/alarme/{metric_alert}', [MetricAlertController::class, 'update'])
                ->name('projects.alerts.update');
            Route::post('{project}/alarme/{metric_alert}/zustand', [MetricAlertController::class, 'toggle'])
                ->name('projects.alerts.toggle');
            Route::delete('{project}/alarme/{metric_alert}', [MetricAlertController::class, 'destroy'])
                ->name('projects.alerts.destroy');

            // Stummschalten (A4). Sie steht unter der Regel und nicht unter der
            // Übersicht, weil `scopeBindings` den Alarm hier über die Beziehung
            // am Projekt auflöst — dieselbe Bindung wie bei den übrigen
            // Alarm-Routen. Anlegen und Aufheben teilen sich die Adresse: es ist
            // dieselbe Sache, einmal gesetzt und einmal genommen.
            Route::post('{project}/alarme/{metric_alert}/stummschaltung', [AlertSnoozeController::class, 'storeForMetricAlert'])
                ->name('projects.alerts.snooze.store');
            Route::delete('{project}/alarme/{metric_alert}/stummschaltung', [AlertSnoozeController::class, 'destroyForMetricAlert'])
                ->name('projects.alerts.snooze.destroy');

            // Alarm-Regeln für Fehler. Der Parametername ist
            // `issue_alert_rule` — aus demselben Grund wie bei den
            // Schwellwert-Alarmen: `scopeBindings` leitet daraus die Beziehung
            // am Projekt ab (`issueAlertRules()`), und mit einem freieren Namen
            // wäre eine Regel über jedes Projekt erreichbar.
            Route::get('{project}/alarmregeln', [IssueAlertRuleController::class, 'index'])
                ->name('projects.issue-alerts.index');
            Route::post('{project}/alarmregeln', [IssueAlertRuleController::class, 'store'])
                ->name('projects.issue-alerts.store');
            // Die Vorschau ändert nichts, ist aber ein POST: sie bezieht sich
            // auf eine Regel, die noch nicht gespeichert ist, und trägt deshalb
            // den ganzen Entwurf im Rumpf.
            Route::post('{project}/alarmregeln/vorschau', [IssueAlertRuleController::class, 'preview'])
                ->name('projects.issue-alerts.preview');
            Route::patch('{project}/alarmregeln/{issue_alert_rule}', [IssueAlertRuleController::class, 'update'])
                ->name('projects.issue-alerts.update');
            Route::post('{project}/alarmregeln/{issue_alert_rule}/zustand', [IssueAlertRuleController::class, 'toggle'])
                ->name('projects.issue-alerts.toggle');
            Route::delete('{project}/alarmregeln/{issue_alert_rule}', [IssueAlertRuleController::class, 'destroy'])
                ->name('projects.issue-alerts.destroy');

            // Stummschalten (A4) — wie bei den Schwellwert-Alarmen unter der
            // Regel selbst.
            Route::post('{project}/alarmregeln/{issue_alert_rule}/stummschaltung', [AlertSnoozeController::class, 'storeForIssueAlertRule'])
                ->name('projects.issue-alerts.snooze.store');
            Route::delete('{project}/alarmregeln/{issue_alert_rule}/stummschaltung', [AlertSnoozeController::class, 'destroyForIssueAlertRule'])
                ->name('projects.issue-alerts.snooze.destroy');

            // Die Alarm-Übersicht (A4): alle Regeln beider Arten mit Zustand und
            // Verlauf, dazu je Regel eine Detailseite. Sie liegt bewusst neben
            // den Einstellungsseiten und nicht in ihnen: dort wird eingerichtet,
            // hier nachgesehen — und wer nach einer Störung nachsieht, will
            // nicht erst wissen, ob es ein Schwellwert-Alarm oder eine
            // Fehler-Regel war.
            //
            // Ansehen darf jedes Mitglied, aus demselben Grund wie bei den
            // Einstellungsseiten.
            Route::get('{project}/alarmuebersicht', [AlertOverviewController::class, 'index'])
                ->name('projects.alert-overview.index');
            Route::get('{project}/alarmuebersicht/alarme/{metric_alert}', [AlertOverviewController::class, 'metricAlert'])
                ->name('projects.alert-overview.metric');
            Route::get('{project}/alarmuebersicht/regeln/{issue_alert_rule}', [AlertOverviewController::class, 'issueAlertRule'])
                ->name('projects.alert-overview.issue');

            // Stichproben-Regeln der Antwortzeiten. Der Parametername ist
            // `sampling_rule` — aus demselben Grund wie bei den
            // Fingerprint-Regeln: `scopeBindings` leitet daraus die Beziehung am
            // Projekt ab (`samplingRules()`).
            Route::get('{project}/stichproben', [SamplingRuleController::class, 'index'])
                ->name('projects.sampling.index');
            Route::post('{project}/stichproben', [SamplingRuleController::class, 'store'])
                ->name('projects.sampling.store');
            Route::patch('{project}/stichproben/{sampling_rule}', [SamplingRuleController::class, 'update'])
                ->name('projects.sampling.update');
            Route::post('{project}/stichproben/{sampling_rule}/zustand', [SamplingRuleController::class, 'toggle'])
                ->name('projects.sampling.toggle');
            Route::delete('{project}/stichproben/{sampling_rule}', [SamplingRuleController::class, 'destroy'])
                ->name('projects.sampling.destroy');

            // Zuständigkeits-Regeln (R6). Der Parametername ist
            // `ownership_rule` — aus demselben Grund wie bei den übrigen
            // Regellisten: `scopeBindings` leitet daraus die Beziehung am
            // Projekt ab (`ownershipRules()`), und eine Regel eines fremden
            // Projekts läuft damit in ein 404 statt in eine Prüfung, die
            // jemand vergessen kann.
            //
            // Die Vorschau und der Import stehen **vor** den Routen mit
            // Regel-Kennung: `zustaendigkeit/vorschau` hat dieselbe Form wie
            // `zustaendigkeit/{ownership_rule}`, und „vorschau" wäre dort eine
            // Kennung.
            Route::get('{project}/zustaendigkeit', [OwnershipRuleController::class, 'index'])
                ->name('projects.ownership.index');
            Route::post('{project}/zustaendigkeit/vorschau', [OwnershipRuleController::class, 'preview'])
                ->name('projects.ownership.preview');
            Route::post('{project}/zustaendigkeit/import', [OwnershipRuleController::class, 'import'])
                ->name('projects.ownership.import');
            Route::patch('{project}/zustaendigkeit/automatik', [OwnershipRuleController::class, 'autoAssign'])
                ->name('projects.ownership.auto-assign');
            Route::post('{project}/zustaendigkeit', [OwnershipRuleController::class, 'store'])
                ->name('projects.ownership.store');
            Route::patch('{project}/zustaendigkeit/{ownership_rule}', [OwnershipRuleController::class, 'update'])
                ->name('projects.ownership.update');
            Route::post('{project}/zustaendigkeit/{ownership_rule}/zustand', [OwnershipRuleController::class, 'toggle'])
                ->name('projects.ownership.toggle');
            Route::delete('{project}/zustaendigkeit/{ownership_rule}', [OwnershipRuleController::class, 'destroy'])
                ->name('projects.ownership.destroy');

            // Datenschutz. Die Seite darf jedes Mitglied ansehen — was von einer
            // Meldung übrig bleibt, muss jeder wissen, der mit den Daten
            // arbeitet; geändert wird sie von der Verwaltung.
            Route::get('{project}/leistungserkennung', [ProjectPerformanceController::class, 'index'])
                ->name('projects.performance.index');
            Route::patch('{project}/leistungserkennung', [ProjectPerformanceController::class, 'update'])
                ->name('projects.performance.update');

            // Bündelung der Benachrichtigungen (A6). Ansehen darf jedes
            // Mitglied: sie beantwortet die Frage, warum eine Meldung erst mit
            // Verzögerung kam — und die stellt sich der, der auf sie gewartet
            // hat, nicht die Verwaltung.
            Route::get('{project}/buendelung', [ProjectDigestController::class, 'index'])
                ->name('projects.digest.index');
            Route::patch('{project}/buendelung', [ProjectDigestController::class, 'update'])
                ->name('projects.digest.update');

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
