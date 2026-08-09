<?php

/*
|--------------------------------------------------------------------------
| Abgelöste Adressen der Fachseiten
|--------------------------------------------------------------------------
|
| Included at the end of routes/web.php — hinter allen echten Routen, damit hier
| nur landet, was es sonst nicht mehr gibt.
|
| Bis U5 lagen die Fachseiten ohne Organisation in der Adresse (`/fehler`,
| `/versionen`, …). Diese Adressen stehen in Lesezeichen und in verschickten
| Links; sie führen deshalb weiterhin ans Ziel — auf dieselbe Seite unter der
| aktiven Organisation, samt Abfrage-Parametern.
|
| Ein Eintrag je altem Wurzelpfad, nicht je alter Route: das Anhängen von
| `organisationen/{slug}/` gilt für jeden Unterpfad, und eine Liste einzelner
| Weiterleitungen wäre die Liste, die beim nächsten Unterpfad nicht mitwächst.
| Was es unter der neuen Adresse nicht gibt, endet dort in einem 404 — die
| Auskunft „gibt es nicht" gibt die neue Adresse, nicht diese Stelle.
|
| Nur GET: ein Lesezeichen ist ein Aufruf, kein Formular. Die schreibenden
| Adressen kommen aus der Oberfläche, und die baut ihre Links über die
| Routen-Namen — die zeigen längst auf die neuen Adressen.
|
*/

use App\Http\Controllers\LegacyOrganizationRedirectController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    $roots = [
        'fehler',
        'merkmale',
        'rueckmeldungen',
        'versionen',
        'leistung',
        'leistungsprobleme',
        'ladeerlebnis',
        'spur',
    ];

    foreach ($roots as $root) {
        Route::get($root.'/{legacyPath?}', LegacyOrganizationRedirectController::class)
            ->where('legacyPath', '.*')
            ->name('legacy.'.$root);
    }
});
