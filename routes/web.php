<?php

use App\Http\Controllers\HomeController;
use App\Jobs\ProcessDemoIngest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Inertia;

// Die Fachseiten liegen unter `/organisationen/{organisation}/…` (U5): jeder Link
// trägt die Organisation bei sich und zeigt beim Empfänger dasselbe. Welche
// Organisation gemeint ist, löst App\Http\Middleware\ResolveOrganization aus der
// Adresse auf — nicht mehr die zuletzt gewählte.
//
// Die Anwendung ist nicht öffentlich: ohne Anmeldung (und ohne bestätigte
// E-Mail-Adresse) führt jeder Aufruf zur Anmeldung bzw. zum Bestätigungshinweis.
Route::middleware(['auth', 'verified'])->group(function () {
    // Der Einstieg ohne Organisation. Er zeigt keine Seite, sondern schickt
    // weiter — auf die Übersicht der aktiven Organisation, und ohne
    // Mitgliedschaft auf die Organisationsliste. Die Adresse bleibt, weil ein
    // frisch angelegtes Konto noch keine Organisation hat und die Anmeldung
    // trotzdem irgendwohin führen muss.
    Route::get('/', HomeController::class)->name('home');

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
// Die drei Einstiegsseiten (Organisation, Projekt, Team). Sie stehen vorn, weil
// alles andere auf sie verlinkt.
require __DIR__.'/overviews.php';
require __DIR__.'/notifications.php';
require __DIR__.'/projects.php';
require __DIR__.'/issues.php';
require __DIR__.'/feedback.php';
require __DIR__.'/performance.php';
require __DIR__.'/discover.php';
require __DIR__.'/dashboards.php';
require __DIR__.'/releases.php';
require __DIR__.'/profiling.php';
require __DIR__.'/replays.php';
require __DIR__.'/traces.php';
require __DIR__.'/operations.php';

// Alles, was eingerichtet wird, unter `/einstellungen/…` — mit eigener
// Unter-Navigation und ohne Filterleiste (U6). Vor den abgelösten Adressen,
// damit deren Weiterleitungen auf etwas zeigen, das es schon gibt.
require __DIR__.'/settings.php';

// Zum Schluss: die alten, organisationslosen Adressen der Fachseiten. Sie
// stehen hinter allem anderen, damit sie nur greifen, wo keine echte Route mehr
// liegt.
require __DIR__.'/legacy.php';
