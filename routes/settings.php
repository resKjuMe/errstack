<?php

/*
|--------------------------------------------------------------------------
| Einstellungen
|--------------------------------------------------------------------------
|
| Included at the end of routes/web.php, vor den abgelösten Adressen.
|
| Alles, was eingerichtet wird, liegt unter `/einstellungen/…` — getrennt von den
| Seiten, auf denen Daten angesehen werden. Der Schnitt verläuft entlang der
| Frage, was man gerade tut: nachsehen oder einstellen. Wer eine Einstellung
| sucht, muss deshalb nicht mehr wissen, ob sie unter der Organisation, unter dem
| Projekt oder im Nutzer-Menü liegt.
|
| Die Gruppe trägt {@see App\Http\Middleware\SettingsArea}: die Marke an der
| Anfrage ist das einzige, woran die Hülle den Bereich erkennt — daraus entstehen
| die Unter-Navigation und das Fehlen der Filterleiste. Wer hier eine Route
| ergänzt, bekommt beides ohne weiteres Zutun.
|
| Die Rechteprüfung bleibt, wo sie war: in den Policies der einzelnen Seiten. Der
| Umzug öffnet nichts, was vorher zu war.
|
*/

use App\Http\Middleware\SettingsArea;
use Illuminate\Support\Facades\Route;

Route::prefix('einstellungen')
    ->middleware(['auth', SettingsArea::class])
    ->group(function () {
        // Das eigene Konto steht außerhalb der `verified`-Schranke — siehe
        // routes/settings/account.php.
        require __DIR__.'/settings/account.php';

        Route::middleware('verified')->group(function () {
            require __DIR__.'/settings/organization.php';
            require __DIR__.'/settings/projects.php';
            require __DIR__.'/settings/notifications.php';
        });
    });
