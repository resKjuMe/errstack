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

use App\Http\Controllers\Ingest\CheckInController;
use App\Http\Controllers\Ingest\EnvelopeController;
use App\Http\Controllers\Ingest\SecurityController;
use App\Http\Controllers\Ingest\StoreController;
use App\Http\Controllers\Ingest\UserFeedbackController;
use Illuminate\Support\Facades\Route;

Route::post('{project}/store', [StoreController::class, 'store'])
    // Die Projektnummer, nicht der Slug: so steht sie in der DSN, und das SDK
    // schickt genau das, was dort steht.
    ->whereNumber('project')
    ->middleware('ingest.key')
    ->name('ingest.store');

// Der Weg heutiger SDKs: mehrere Elemente in einer Anfrage — Fehler,
// Transaktion, Sitzung, Anhang. `/store/` bleibt daneben bestehen; ältere SDKs
// kennen den Envelope nicht, und keines von beiden soll ausgeschlossen werden.
Route::post('{project}/envelope', [EnvelopeController::class, 'store'])
    ->whereNumber('project')
    ->middleware('ingest.key')
    ->name('ingest.envelope');

// Die Sicherheitsberichte des Browsers (CSP, Expect-CT, Expect-Staple). Auch
// hier steht kein SDK dahinter, sondern eine Kopfzeile der überwachten
// Anwendung: `report-uri` nimmt eine Adresse und sonst nichts, weshalb der
// Schlüssel im Abfrageteil steht (`?sentry_key=…`). Der Content-Type ist
// bewusst nicht eingeschränkt — die Browser schicken drei verschiedene, und
// welcher Bericht ankam, steht ohnehin im Rumpf.
Route::post('{project}/security', [SecurityController::class, 'store'])
    ->whereNumber('project')
    ->middleware('ingest.key')
    ->name('ingest.security');

// Die Beschreibung einer betroffenen Person (M6) — und der Weg des
// mitgelieferten Widgets. Zwei Schreibweisen, weil beide im Umlauf sind:
// `/user-feedback/` ist Sentrys heutige, `/user-report/` die ältere.
//
// Die Ratenbegrenzung steht hier und nirgends sonst in dieser Datei: an allen
// anderen Adressen meldet eine Anwendung, hier drückt ein Mensch auf
// „Absenden" — und der Schlüssel dazu steht in jedem JavaScript-Bundle.
// Gezählt wird je Absender-Adresse und Projekt (App\Providers\AppServiceProvider).
Route::post('{project}/{feedback}', [UserFeedbackController::class, 'store'])
    ->whereNumber('project')
    ->where('feedback', 'user-feedback|user-report')
    ->middleware(['throttle:ingest-feedback', 'ingest.key'])
    ->name('ingest.feedback');

// Lebenszeichen eines überwachten Cronjobs, ohne SDK: der Schlüssel steht hier
// in der Adresse statt in einer Kopfzeile (siehe App\Support\Ingest\IngestAuth),
// damit ein `curl` am Ende eines Shell-Skripts genügt. `GET` ist zugelassen,
// weil die Gegenstelle oft nur Adressen aufrufen kann.
Route::match(['get', 'post'], '{project}/cron/{monitor}/{key}', [CheckInController::class, 'store'])
    ->whereNumber('project')
    ->where('monitor', '[A-Za-z0-9._-]{1,64}')
    ->where('key', '[A-Za-z0-9]{1,64}')
    ->middleware('ingest.key')
    ->name('ingest.cron');
