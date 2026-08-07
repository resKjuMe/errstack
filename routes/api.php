<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Registered by bootstrap/app.php under the "api" middleware group and the
| /api prefix. Die versionierte öffentliche Schnittstelle liegt in
| routes/api-v0.php, die Aufnahme eingehender Fehlermeldungen in
| routes/ingest.php.
|
*/

Route::get('/ping', fn () => ['ok' => true])->name('api.ping');

require __DIR__.'/api-v0.php';

// Nach der Schnittstelle eingebunden: die Aufnahme liegt unter `{project}/…`,
// und die erste passende Route gewinnt.
require __DIR__.'/ingest.php';
