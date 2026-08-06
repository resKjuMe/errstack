<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Registered by bootstrap/app.php under the "api" middleware group and the
| /api prefix. Die versionierte öffentliche Schnittstelle liegt in
| routes/api-v0.php; die Endpunkte zur Aufnahme von Fehlermeldungen kommen mit
| den Aufnahme-Phasen dazu.
|
*/

Route::get('/ping', fn () => ['ok' => true])->name('api.ping');

require __DIR__.'/api-v0.php';
