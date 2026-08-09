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
| Die Begrenzung (O1) läuft in zwei Stufen, und ihre Reihenfolge ist der Punkt:
|
|   ingest.throttle    — **vor** `ingest.key`, damit auch das Durchprobieren von
|                        Schlüsseln gedrosselt wird. Zählt je Herkunft und fasst
|                        die Datenbank nicht an.
|   ingest.quota:<art> — dahinter, weil erst dort feststeht, wessen Kontingent
|                        gilt. Der Parameter nennt die Datenart; wo keiner steht
|                        (Envelope), prüft diese Stufe nur die Rate des
|                        Schlüssels und die Datenarten fallen je Element an.
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
    // Hier steht die Datenart fest: über diesen Weg kommen ausschließlich
    // Fehlermeldungen.
    ->middleware(['ingest.throttle', 'ingest.key', 'ingest.quota:errors'])
    ->name('ingest.store');

// Der Weg heutiger SDKs: mehrere Elemente in einer Anfrage — Fehler,
// Transaktion, Sitzung, Anhang. `/store/` bleibt daneben bestehen; ältere SDKs
// kennen den Envelope nicht, und keines von beiden soll ausgeschlossen werden.
Route::post('{project}/envelope', [EnvelopeController::class, 'store'])
    ->whereNumber('project')
    // Ohne Datenart: was im Envelope steckt, weiß vor dem Zerlegen niemand.
    // Geprüft wird hier die Rate des Schlüssels, die Kontingente je Datenart
    // fallen Element für Element an (App\Support\Ingest\EnvelopeIntake) —
    // und deshalb nimmt ein aufgebrauchtes Transaktions-Kontingent die
    // Fehlermeldung daneben nicht mit.
    ->middleware(['ingest.throttle', 'ingest.key', 'ingest.quota'])
    ->name('ingest.envelope');

// Die Sicherheitsberichte des Browsers (CSP, Expect-CT, Expect-Staple). Auch
// hier steht kein SDK dahinter, sondern eine Kopfzeile der überwachten
// Anwendung: `report-uri` nimmt eine Adresse und sonst nichts, weshalb der
// Schlüssel im Abfrageteil steht (`?sentry_key=…`). Der Content-Type ist
// bewusst nicht eingeschränkt — die Browser schicken drei verschiedene, und
// welcher Bericht ankam, steht ohnehin im Rumpf.
Route::post('{project}/security', [SecurityController::class, 'store'])
    ->whereNumber('project')
    // Ein Sicherheitsbericht wird zu einer Fehlermeldung und zählt deshalb
    // gegen dieselbe Datenart.
    ->middleware(['ingest.throttle', 'ingest.key', 'ingest.quota:errors'])
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
    // Kein `ingest.quota`: eine Rückmeldung ist die Beschreibung eines
    // Menschen zu einem Ereignis, das bereits gezählt wurde
    // ({@see App\Enums\IngestType::countsTowardEventQuota()}). Die
    // Ratenbegrenzung darüber ist die eigene dieser Adresse.
    ->middleware(['throttle:ingest-feedback', 'ingest.throttle', 'ingest.key'])
    ->name('ingest.feedback');

// Lebenszeichen eines überwachten Cronjobs, ohne SDK: der Schlüssel steht hier
// in der Adresse statt in einer Kopfzeile (siehe App\Support\Ingest\IngestAuth),
// damit ein `curl` am Ende eines Shell-Skripts genügt. `GET` ist zugelassen,
// weil die Gegenstelle oft nur Adressen aufrufen kann.
Route::match(['get', 'post'], '{project}/cron/{monitor}/{key}', [CheckInController::class, 'store'])
    ->whereNumber('project')
    ->where('monitor', '[A-Za-z0-9._-]{1,64}')
    ->where('key', '[A-Za-z0-9]{1,64}')
    ->middleware(['ingest.throttle', 'ingest.key', 'ingest.quota:monitors'])
    ->name('ingest.cron');
