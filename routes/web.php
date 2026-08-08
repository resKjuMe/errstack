<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PerformanceController;
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
    // Die Übersicht ist die erste Seite mit der globalen Filterleiste; ihr
    // Zustand steht in der Adresszeile und wird serverseitig aufgelöst.
    Route::get('/', DashboardController::class)->name('dashboard');

    // Die erste Auswertungsseite: Antwortzeiten, Durchsatz und Fehlerrate je
    // Transaktion. Sie nutzt dieselbe Filterleiste und ergänzt sie um Suche,
    // Sortierung und Seitenzahl — alles in der Adresszeile.
    Route::get('/leistung', PerformanceController::class)->name('performance.index');

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
require __DIR__.'/notifications.php';
require __DIR__.'/projects.php';
require __DIR__.'/issues.php';
require __DIR__.'/releases.php';
require __DIR__.'/api-tokens.php';
