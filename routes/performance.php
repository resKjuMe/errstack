<?php

/*
|--------------------------------------------------------------------------
| Leistung
|--------------------------------------------------------------------------
|
| Included at the end of routes/web.php.
|
| Alles hier liegt unter `/organisationen/{organisation}/…` (U5): welche
| Organisation gemeint ist, steht in der Adresse und nicht in der zuletzt
| gewählten. Aufgelöst und auf Mitgliedschaft geprüft wird sie in
| App\Http\Middleware\ResolveOrganization.
|
| Die Auswertung der Antwortzeiten: die Übersicht („wohin soll ich schauen")
| und die Detailanalyse einer einzelnen Transaktion („warum ist das langsam").
|
| Wie die Fehlerliste hängen beide nicht an einem Projekt in der Adresszeile:
| welche Projekte gemeint sind, sagt die globale Filterleiste.
|
| Die Detailseite trägt Name und Operation als Parameter und nicht als
| Pfad-Abschnitte. Ein Transaktionsname ist in aller Regel ein Pfad und bringt
| damit genau die Zeichen mit, die ein Abschnitt nicht tragen kann.
|
| Dazu die Leistungsprobleme (PF6). Eine eigene Adresse und nicht ein Filter auf
| der Fehlerliste: sie beantworten eine andere Frage („was kostet Zeit?" statt
| „was ist kaputt?") und zeigen deshalb andere Spalten.
|
| Und die Trend-Liste (PF7) unterhalb der Übersicht. Sie steht dort und nicht
| als eigener Punkt in der Hauptnavigation: sie ist die Vertiefung genau einer
| Spalte der Übersicht — des Pfeils —, und wer sie sucht, sucht sie bei der
| Leistung. Die Kopfzeile trägt bereits zwei Leistungs-Einträge; ein dritter
| wäre der, bei dem niemand mehr weiß, welcher wofür ist.
|
| Dazu das Ladeerlebnis im Browser (PF5). Ebenfalls eine eigene Adresse: die
| Antwortzeit des Servers und das, was ein Besucher tatsächlich erlebt, sind
| zwei verschiedene Messungen mit verschiedenen Einheiten und verschiedenen
| Schwellen. Auf einer Seite nebeneinander gestellt, sähen sie aus wie zwei
| Spalten derselben Zahl — und die häufigste Fehldeutung wäre „der Server ist
| schnell, also ist die Seite schnell".
|
*/

use App\Http\Controllers\PerformanceController;
use App\Http\Controllers\PerformanceIssueController;
use App\Http\Controllers\PerformanceTrendController;
use App\Http\Controllers\TransactionDetailController;
use App\Http\Controllers\WebVitalController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])
    ->prefix('organisationen/{organization}')
    ->group(function () {
        Route::get('leistung', PerformanceController::class)->name('performance.index');

        Route::get('leistung/transaktion', TransactionDetailController::class)
            ->name('performance.transaction');

        Route::get('leistung/trends', [PerformanceTrendController::class, 'index'])
            ->name('performance.trends.index');

        // Abhaken und wieder aufheben unter derselben Adresse, unterschieden nur
        // durch das Verfahren: es ist ein Schalter und keine zwei Handlungen — die
        // Oberfläche schickt POST oder DELETE an dieselbe Stelle.
        Route::post('leistung/trends/{trend}/gesehen', [PerformanceTrendController::class, 'store'])
            ->name('performance.trends.seen');

        Route::delete('leistung/trends/{trend}/gesehen', [PerformanceTrendController::class, 'destroy'])
            ->name('performance.trends.unseen');

        Route::get('leistungsprobleme', [PerformanceIssueController::class, 'index'])
            ->name('performance.issues.index');

        Route::get('leistungsprobleme/{issue}', [PerformanceIssueController::class, 'show'])
            ->name('performance.issues.show');

        Route::get('ladeerlebnis', [WebVitalController::class, 'index'])
            ->name('web-vitals.index');

        // Die Seite als Parameter und nicht als Pfad-Abschnitt: ihr Name ist ein
        // Pfad und bringt damit genau die Zeichen mit, die ein Abschnitt nicht
        // tragen kann — dieselbe Überlegung wie bei der Transaktions-Detailseite.
        Route::get('ladeerlebnis/seite', [WebVitalController::class, 'show'])
            ->name('web-vitals.show');
    });
