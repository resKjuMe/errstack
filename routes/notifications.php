<?php

/*
|--------------------------------------------------------------------------
| Benachrichtigungswege und Zustellprotokoll
|--------------------------------------------------------------------------
|
| Included at the end of routes/web.php. Wie bei den Organisationen steckt die
| Rechteprüfung in den Policies (App\Policies), nicht in einer Middleware.
|
*/

use App\Http\Controllers\NotificationChannelController;
use App\Http\Controllers\NotificationDeliveryController;
use App\Http\Controllers\NotificationPreferenceController;
use App\Http\Controllers\NotificationUnsubscribeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    // Vor den Kanal-Routen, damit „einstellungen" nicht als Kanal-Kennung
    // gelesen wird.
    Route::get('benachrichtigungen/einstellungen', [NotificationPreferenceController::class, 'index'])
        ->name('notifications.preferences');
    Route::put('benachrichtigungen/einstellungen', [NotificationPreferenceController::class, 'update'])
        ->name('notifications.preferences.update');
    Route::put('benachrichtigungen/einstellungen/ruhezeiten', [NotificationPreferenceController::class, 'quietHours'])
        ->name('notifications.preferences.quiet-hours');
    Route::post('benachrichtigungen/einstellungen/abbestellen', [NotificationPreferenceController::class, 'subscription'])
        ->name('notifications.preferences.subscription');
    // Die Bündelung für sich abschalten (A6) — die Gegenrichtung zur
    // Einstellung am Projekt.
    Route::post('benachrichtigungen/einstellungen/buendelung', [NotificationPreferenceController::class, 'digest'])
        ->name('notifications.preferences.digest');

    Route::get('organisationen/{organization}/benachrichtigungen', [NotificationChannelController::class, 'index'])
        ->name('notifications.index');
    Route::post('organisationen/{organization}/benachrichtigungen', [NotificationChannelController::class, 'store'])
        ->name('notifications.store');

    Route::patch('benachrichtigungen/{channel}', [NotificationChannelController::class, 'update'])
        ->name('notifications.update');
    Route::delete('benachrichtigungen/{channel}', [NotificationChannelController::class, 'destroy'])
        ->name('notifications.destroy');
    Route::post('benachrichtigungen/{channel}/test', [NotificationChannelController::class, 'test'])
        ->name('notifications.test');

    Route::post('zustellungen/{delivery}/wiederholen', [NotificationDeliveryController::class, 'retry'])
        ->name('deliveries.retry');
});

/*
| Abmelden aus der Mail heraus — ohne Anmeldung, dafür signiert. Wer eine Mail
| erhält, ist nicht zwingend angemeldet (und liest sie oft auf einem anderen
| Gerät); ein Abmelde-Link hinter der Anmeldung wäre praktisch keiner.
|
| Anzeige und Ausführung teilen sich dieselbe Adresse: die Signatur gilt für
| die Adresse, nicht für das Verfahren, deshalb schickt das Formular per POST
| auf genau dieselbe URL zurück.
*/
Route::middleware('signed')->group(function () {
    Route::get('benachrichtigungen/abmelden/{user}/{event}', [NotificationUnsubscribeController::class, 'show'])
        ->name('notifications.unsubscribe');
    Route::post('benachrichtigungen/abmelden/{user}/{event}', [NotificationUnsubscribeController::class, 'store'])
        ->name('notifications.unsubscribe.apply');
});
