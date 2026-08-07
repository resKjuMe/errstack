<?php

use App\Jobs\ProcessDemoIngest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Inertia;

// Beispielseiten des Oberflächen-Grundgerüsts: „Übersicht" zeigt den Rahmen im
// Einsatz, „Bausteine" ist die Musterseite aller wiederverwendbaren Bausteine.
// Fachseiten kommen mit den folgenden Phasen und ersetzen diese Routen.
//
// Die Anwendung ist nicht öffentlich: ohne Anmeldung (und ohne bestätigte
// E-Mail-Adresse) führt jeder Aufruf zur Anmeldung bzw. zum Bestätigungshinweis.
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/', fn () => Inertia::render('Dashboard'))->name('dashboard');
    Route::get('/bausteine', fn () => Inertia::render('Components'))->name('components');
});

// Beispiel-Ingest in die Warteschlange legen. Der Job läuft im Hintergrund und
// meldet sein Ergebnis per Broadcast an alle offenen Ansichten.
Route::post('/demo/ingest', function (Request $request) {
    $reference = Str::upper(Str::random(6));

    ProcessDemoIngest::dispatch($reference, $request->boolean('fail'));

    return back()->with('status', "Ingest {$reference} eingereiht.");
})->name('demo.ingest');

require __DIR__.'/auth.php';
require __DIR__.'/organizations.php';
require __DIR__.'/projects.php';
require __DIR__.'/api-tokens.php';
