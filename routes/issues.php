<?php

/*
|--------------------------------------------------------------------------
| Fehler
|--------------------------------------------------------------------------
|
| Included at the end of routes/web.php.
|
| Alles hier liegt unter `/organisationen/{organisation}/…` (U5): welche
| Organisation gemeint ist, steht in der Adresse und nicht in der zuletzt
| gewählten — sonst zeigt ein verschickter Link beim Empfänger etwas anderes.
| Aufgelöst und auf Mitgliedschaft geprüft wird sie in
| App\Http\Middleware\ResolveOrganization.
|
| Das Projekt steht weiterhin **nicht** in der Adresszeile: welche Projekte
| gemeint sind, sagt die globale Filterleiste und damit ohnehin die Adresse. Zwei
| Wege, ein Projekt zu wählen, wären einer zu viel.
|
| Innerhalb der Organisation steckt die Rechteprüfung nicht in einer Middleware:
| der Filter löst die wählbaren Projekte über die Mitgliedschaft des Betrachters
| auf (App\Support\Filters\GlobalFilter) — was er nicht sehen darf, steht gar
| nicht erst in der Auswahl. Für die Detailseite gilt das nicht: sie wird über
| eine Kennung aufgerufen, nicht über eine Auswahl, und prüft deshalb
| ausdrücklich (App\Policies\IssuePolicy).
|
*/

use App\Http\Controllers\IssueActionController;
use App\Http\Controllers\IssueAssignmentController;
use App\Http\Controllers\IssueAttachmentController;
use App\Http\Controllers\IssueCommentController;
use App\Http\Controllers\IssueController;
use App\Http\Controllers\IssueDetailController;
use App\Http\Controllers\IssueMergeController;
use App\Http\Controllers\IssueSearchController;
use App\Http\Controllers\IssueTagController;
use App\Http\Controllers\SavedSearchController;
use App\Http\Controllers\TagController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])
    ->prefix('organisationen/{organization}')
    ->group(function () {
        Route::get('fehler', IssueController::class)->name('issues.index');

        // Die Vorschläge des Suchfeldes. Sie stehen **vor** den Fehler-Routen, und
        // das ist keine Ordnungsfrage: `fehler/{issue}/merkmale` hat dieselbe Form,
        // und „suche" wäre dort eine Fehlerkennung.
        Route::get('fehler/suche/vorschlaege', [IssueSearchController::class, 'suggest'])
            ->name('issues.search.suggest');

        // Wem sich ein Fehler zuweisen lässt (S7). Steht aus demselben Grund wie
        // die Suchvorschläge **vor** den Fehler-Routen und liegt bewusst nicht
        // unter einem einzelnen Fehler: die Auswahlliste ist bei einer
        // Sammelaktion über 12.480 Einträge dieselbe, und eine Adresse
        // `fehler/{issue}/zustaendigkeit/vorschlaege` müsste dafür eine Kennung
        // erfinden. Welche Organisation gemeint ist, sagt die Filterleiste.
        Route::get('fehler/zustaendigkeit/vorschlaege', IssueAssignmentController::class)
            ->name('issues.assignment.suggest');

        // Die gespeicherten Suchen (S5). Sie stehen aus demselben Grund wie die
        // Vorschläge **vor** den Fehler-Routen: `fehler/{issue}` würde „suchen"
        // sonst für eine Fehlerkennung halten.
        //
        // Sie liegen unter „fehler" und nicht unter einem Projekt, obwohl sich eine
        // davon als Einstieg **eines** Projekts festlegen lässt: gespeichert wird
        // eine Ansicht der Fehlerliste, und die gehört keinem einzelnen Projekt.
        // Welches Projekt beim Festlegen gemeint ist, steht deshalb im Rumpf — wie
        // bei den Sammelaktionen und aus demselben Grund.
        //
        // Eine Route zum **Anwenden** gibt es nicht: eine Suche ist ein Ausdruck und
        // eine Sortierung, und beides steht in der Adresszeile der Liste. Der Link
        // dorthin ist die Anwendung.
        Route::post('fehler/suchen', [SavedSearchController::class, 'store'])
            ->name('issues.searches.store');
        Route::patch('fehler/suchen/{search}', [SavedSearchController::class, 'update'])
            ->name('issues.searches.update');
        Route::delete('fehler/suchen/{search}', [SavedSearchController::class, 'destroy'])
            ->name('issues.searches.destroy');
        Route::put('fehler/suchen/{search}/standard', [SavedSearchController::class, 'setDefault'])
            ->name('issues.searches.default.store');
        Route::delete('fehler/suchen/{search}/standard', [SavedSearchController::class, 'clearDefault'])
            ->name('issues.searches.default.destroy');

        // Die Zustandsaktionen (S6) — eine Adresse für einen Fehler wie für
        // zwölftausend. Sie steht **neben** der Liste und nicht unter einem
        // Eintrag: eine Sammelaktion meint keine einzelne Kennung, sondern die
        // Auswahl, und ein Pfad `fehler/{issue}/aktion` müsste für sie eine
        // Kennung erfinden. Welche Einträge gemeint sind, steht im Rumpf — samt
        // der Filterfelder, mit denen die Liste gebaut wurde.
        Route::post('fehler/aktionen', [IssueActionController::class, 'store'])
            ->name('issues.actions.store');
        Route::post('fehler/aktionen/rueckgaengig', [IssueActionController::class, 'undo'])
            ->name('issues.actions.undo');

        // Die Detailseite steht unter dem Fehler, die einzelne Meldung darunter:
        // ohne Meldung in der Adresszeile zeigt die Seite die neueste. So ist „der
        // Fehler" verlinkbar, ohne dass der Link auf ein Ereignis zeigt, das morgen
        // nicht mehr das neueste ist — und ein Link auf **diese** eine Meldung ist
        // trotzdem möglich.
        // Das Zusammenführen steht **über** der Detailseite und nicht unter einem
        // Eintrag: welcher Eintrag der Kopf wird, entscheidet sich erst beim
        // Zusammenführen — eine Adresse `fehler/{issue}/zusammenfuehren` würde das
        // Ergebnis vorwegnehmen. Das Auftrennen dagegen meint genau eine
        // Untergruppe und steht deshalb unter ihr.
        Route::post('fehler/zusammenfuehren', [IssueMergeController::class, 'store'])
            ->name('issues.merge.store');
        Route::delete('fehler/{issue}/zusammenfuehrung', [IssueMergeController::class, 'destroy'])
            ->name('issues.merge.destroy');

        Route::get('fehler/{issue}', [IssueDetailController::class, 'show'])->name('issues.show');
        Route::get('fehler/{issue}/ereignisse/{event}', [IssueDetailController::class, 'show'])
            ->name('issues.events.show');
        Route::get('fehler/{issue}/ereignisse/{event}/rohdaten', [IssueDetailController::class, 'raw'])
            ->name('issues.events.raw');

        // Die Anhänge einer Meldung (M5). Sie stehen unter der Meldung und nicht
        // unter dem Fehler: ein Screenshot gehört zu **einem** Absturz, so wie der
        // Stacktrace darüber. Alle drei Kennungen stehen damit in der Adresszeile
        // und werden geprüft (siehe IssueAttachmentController); eine Bindung über
        // `scopeBindings` gibt es nicht, weil der Anhang absichtlich keinen
        // Fremdschlüssel auf die Meldung trägt — er trifft regelmäßig vor ihr ein.
        //
        // Herunterladen und Ansehen sind zwei Adressen und nicht ein Schalter an
        // einer: die eine liefert die Datei als Anhang aus, die andere inline in
        // den Browser — und was inline darf, ist eine Sicherheitsentscheidung, die
        // nicht an einem Abfrageparameter hängen soll.
        Route::get('fehler/{issue}/ereignisse/{event}/anhaenge/{attachment}', [IssueAttachmentController::class, 'show'])
            ->name('issues.attachments.show');
        Route::get('fehler/{issue}/ereignisse/{event}/anhaenge/{attachment}/vorschau', [IssueAttachmentController::class, 'preview'])
            ->name('issues.attachments.preview');
        Route::delete('fehler/{issue}/ereignisse/{event}/anhaenge/{attachment}', [IssueAttachmentController::class, 'destroy'])
            ->name('issues.attachments.destroy');

        // Die Kommentare eines Fehlers (S10). Sie stehen unter ihm, weil sie ihm
        // gehören — anders als die Zustandsaktionen, die auch eine ganze Auswahl
        // meinen können. Die Kennung des Fehlers steht auch im Pfad des einzelnen
        // Kommentars: sie ist dort keine Verzierung, sondern wird geprüft (siehe
        // IssueCommentController), damit eine vertauschte Adresszeile keinen
        // fremden Kommentar unter fremdem Fehler ändert.
        //
        // Die Vorschläge fürs `@` stehen **vor** der Kommentarkennung, aus
        // demselben Grund wie die Suchvorschläge oben: „vorschlaege" wäre dort
        // sonst eine Kennung.
        Route::get('fehler/{issue}/kommentare/vorschlaege', [IssueCommentController::class, 'suggest'])
            ->name('issues.comments.suggest');
        Route::post('fehler/{issue}/kommentare', [IssueCommentController::class, 'store'])
            ->name('issues.comments.store');
        Route::patch('fehler/{issue}/kommentare/{comment}', [IssueCommentController::class, 'update'])
            ->name('issues.comments.update');
        Route::delete('fehler/{issue}/kommentare/{comment}', [IssueCommentController::class, 'destroy'])
            ->name('issues.comments.destroy');

        // Die Merkmale eines Fehlers (S3). Sie hängen am Eintrag und stehen deshalb
        // unter ihm — anders als die Liste, die keinen einzelnen Eintrag meint.
        Route::get('fehler/{issue}/merkmale', [IssueTagController::class, 'index'])
            ->name('issues.tags.index');
        Route::get('fehler/{issue}/merkmale/{key}', [IssueTagController::class, 'show'])
            ->name('issues.tags.show');

        // Dieselbe Auswertung über die gewählten Projekte. Sie steht wie die
        // Fehlerliste nicht unter einem Projekt: welche gemeint sind, sagt die
        // Filterleiste.
        Route::get('merkmale', [TagController::class, 'index'])->name('tags.index');
        Route::get('merkmale/{key}', [TagController::class, 'show'])->name('tags.show');
    });
