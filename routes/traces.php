<?php

/*
|--------------------------------------------------------------------------
| Spuren (Traces)
|--------------------------------------------------------------------------
|
| Included at the end of routes/web.php.
|
| Alles hier liegt unter `/organisationen/{organisation}/…` (U5): welche
| Organisation gemeint ist, steht in der Adresse und nicht in der zuletzt
| gewählten. Aufgelöst und auf Mitgliedschaft geprüft wird sie in
| App\Http\Middleware\ResolveOrganization.
|
| Eine Spur hängt an keinem Projekt in der Adresszeile, und das ist keine
| Nachlässigkeit, sondern ihr Wesen: sie führt über mehrere Dienste und damit
| über mehrere Projekte. Ein Pfad `/projekte/{projekt}/spur/{spur}` müsste eines
| davon zum Hauptprojekt erklären — und beim Aufruf aus dem zweiten Dienst wäre
| es ein anderes, mit einer anderen Adresse für denselben Ablauf.
|
| Die Rechteprüfung steckt deshalb auch nicht in einer Middleware am Pfad,
| sondern in der Ansicht: sie zeigt genau die Teile der Spur, deren Projekte der
| Betrachter sehen darf. Was fehlt, wird als Lücke sichtbar und nicht
| verschwiegen.
|
*/

use App\Http\Controllers\TraceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])
    ->prefix('organisationen/{organization}')
    ->group(function () {
        // Der Einstieg von einem Fehler aus. Steht **vor** der Spur-Route, obwohl
        // deren Bedingung ihn ohnehin durchließe: wer die Reihenfolge liest, soll
        // nicht erst die Bedingung prüfen müssen, um zu sehen, dass sich die beiden
        // nicht in die Quere kommen.
        Route::get('spur/ereignis/{event}', [TraceController::class, 'fromEvent'])
            ->name('traces.event');

        Route::get('spur/{trace}', [TraceController::class, 'show'])
            ->where('trace', '[0-9a-fA-F]{32}')
            ->name('traces.show');
    });
