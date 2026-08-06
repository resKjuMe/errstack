<?php

/*
|--------------------------------------------------------------------------
| API-Tokens (Verwaltung in der Oberfläche)
|--------------------------------------------------------------------------
|
| Included at the end of routes/web.php. Gilt immer für die gerade aktive
| Organisation; die Rechteprüfung steckt in der ApiTokenPolicy.
|
| Nicht zu verwechseln mit routes/api-v0.php — das ist die Schnittstelle selbst,
| die mit diesen Tokens benutzt wird.
|
*/

use App\Http\Controllers\ApiTokenController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('zugriffstoken', [ApiTokenController::class, 'index'])
        ->name('api-tokens.index');
    Route::post('zugriffstoken', [ApiTokenController::class, 'store'])
        ->name('api-tokens.store');
    Route::delete('zugriffstoken/{apiToken}', [ApiTokenController::class, 'destroy'])
        ->name('api-tokens.destroy');
});
