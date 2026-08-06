<?php

use Illuminate\Support\Facades\Route;
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

require __DIR__.'/auth.php';
