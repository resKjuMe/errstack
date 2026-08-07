<?php

/*
|--------------------------------------------------------------------------
| Datenaufnahme
|--------------------------------------------------------------------------
|
| Eingebunden von routes/api.php und damit unter `/api/…` erreichbar. Die
| Adressen sind Sentrys Adressen — Pfad, Projektnummer und Antwortform
| eingeschlossen —, damit ein unverändertes SDK hierher meldet, sobald in seiner
| DSN dieser Host steht. Sie deshalb nicht „aufräumen": jede Abweichung ist ein
| SDK, das nicht mehr meldet.
|
| Kein `auth:sanctum`: hier meldet sich keine Person mit einem Token an, sondern
| eine Anwendung mit ihrem Client-Schlüssel. Den prüft `ingest.key`.
|
| Die Ratenbegrenzung fehlt bewusst noch — Kontingente je Projekt und Schlüssel
| sind ein eigener Schritt (O1) und gehören dann vor `ingest.key`, damit auch das
| Durchprobieren von Schlüsseln gedrosselt wird.
|
*/

use App\Http\Controllers\Ingest\StoreController;
use Illuminate\Support\Facades\Route;

Route::post('{project}/store', [StoreController::class, 'store'])
    // Die Projektnummer, nicht der Slug: so steht sie in der DSN, und das SDK
    // schickt genau das, was dort steht.
    ->whereNumber('project')
    ->middleware('ingest.key')
    ->name('ingest.store');
