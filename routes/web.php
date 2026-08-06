<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Beispielseiten des Oberflächen-Grundgerüsts: „Übersicht" zeigt den Rahmen im
// Einsatz, „Bausteine" ist die Musterseite aller wiederverwendbaren Bausteine.
// Fachseiten kommen mit den folgenden Phasen und ersetzen diese Routen.
Route::get('/', fn () => Inertia::render('Dashboard'))->name('dashboard');
Route::get('/bausteine', fn () => Inertia::render('Components'))->name('components');

require __DIR__.'/auth.php';
