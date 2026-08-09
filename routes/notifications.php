<?php

/*
|--------------------------------------------------------------------------
| Abmelden aus der Mail heraus
|--------------------------------------------------------------------------
|
| Included at the end of routes/web.php.
|
| Die Kanäle der Organisation, die eigenen Einstellungen und das
| Zustellprotokoll liegen seit U6 im Einstellungsbereich:
| routes/settings/notifications.php.
|
| Hier bleibt der Weg hinaus — ohne Anmeldung, dafür signiert. Wer eine Mail
| erhält, ist nicht zwingend angemeldet (und liest sie oft auf einem anderen
| Gerät); ein Abmelde-Link hinter der Anmeldung wäre praktisch keiner. Aus
| demselben Grund bleibt auch die Adresse selbst, wo sie war: sie steht in
| bereits verschickten Mails.
|
| Anzeige und Ausführung teilen sich dieselbe Adresse: die Signatur gilt für
| die Adresse, nicht für das Verfahren, deshalb schickt das Formular per POST
| auf genau dieselbe URL zurück.
|
*/

use App\Http\Controllers\NotificationUnsubscribeController;
use Illuminate\Support\Facades\Route;

Route::middleware('signed')->group(function () {
    Route::get('benachrichtigungen/abmelden/{user}/{event}', [NotificationUnsubscribeController::class, 'show'])
        ->name('notifications.unsubscribe');
    Route::post('benachrichtigungen/abmelden/{user}/{event}', [NotificationUnsubscribeController::class, 'store'])
        ->name('notifications.unsubscribe.apply');
});
