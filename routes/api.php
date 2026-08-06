<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Registered by bootstrap/app.php under the "api" middleware group and the
| /api prefix. Still empty: the ingest and read endpoints arrive with the
| feature phases.
|
*/

Route::get('/ping', fn () => ['ok' => true])->name('api.ping');
