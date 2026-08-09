<?php

/*
|--------------------------------------------------------------------------
| Einstellungen: das eigene Konto
|--------------------------------------------------------------------------
|
| Included aus routes/settings.php (Präfix `einstellungen`, Middleware `auth`).
|
| Profil und Zugriffstoken lagen bis U6 im Nutzer-Menü — zwei Einträge, die man
| erst findet, wenn man sie sucht. Sie stehen jetzt als eigene Gruppe „Konto" in
| der Unter-Navigation der Einstellungen, neben allem anderen, was eingerichtet
| wird.
|
| Das Profil bewusst ohne `verified` (wie zuvor in routes/auth.php): aus einem
| Tippfehler in der E-Mail-Adresse käme man sonst nicht mehr heraus. Die
| Zugriffstoken behalten ihre Schranke.
|
*/

use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('konto/profil', [ProfileController::class, 'edit'])->name('profile.edit');
Route::patch('konto/profil', [ProfileController::class, 'update'])->name('profile.update');
Route::delete('konto/profil', [ProfileController::class, 'destroy'])->name('profile.destroy');

// Die Tokens der Oberfläche — nicht zu verwechseln mit routes/api-v0.php, der
// Schnittstelle, die mit ihnen benutzt wird. Sie gelten für die gerade aktive
// Organisation; die Rechteprüfung steckt in der ApiTokenPolicy.
Route::middleware('verified')->group(function () {
    Route::get('konto/zugriffstoken', [ApiTokenController::class, 'index'])
        ->name('api-tokens.index');
    Route::post('konto/zugriffstoken', [ApiTokenController::class, 'store'])
        ->name('api-tokens.store');
    Route::delete('konto/zugriffstoken/{apiToken}', [ApiTokenController::class, 'destroy'])
        ->name('api-tokens.destroy');
});
