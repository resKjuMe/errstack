<?php

/*
|--------------------------------------------------------------------------
| Einstellungen: Benachrichtigungen
|--------------------------------------------------------------------------
|
| Included aus routes/settings.php (Präfix `einstellungen`, Middleware `auth`
| und `verified`). Wie bei den Organisationen steckt die Rechteprüfung in den
| Policies (App\Policies), nicht in einer Middleware.
|
| Zwei Ebenen unter einer Überschrift: die Kanäle der Organisation — wohin
| überhaupt zugestellt wird — und die eigenen Einstellungen, wer davon was
| bekommen möchte. Sie stehen nebeneinander, weil die Frage dieselbe ist und die
| Antwort nur an zwei Stellen gegeben wird.
|
| Der Abmelde-Link aus der Mail bleibt außerhalb: er ist signiert statt
| angemeldet und steht weiterhin in routes/notifications.php.
|
*/

use App\Http\Controllers\NotificationChannelController;
use App\Http\Controllers\NotificationDeliveryController;
use App\Http\Controllers\NotificationPreferenceController;
use Illuminate\Support\Facades\Route;

// Die eigenen Einstellungen liegen unter `eigene` und nicht unter
// `einstellungen`: der Bereich heißt schon so, und `einstellungen/
// benachrichtigungen/einstellungen` wäre eine Adresse, die sich selbst
// wiederholt.
//
// Vor den Kanal-Routen, damit „eigene" nicht als Kanal-Kennung gelesen wird.
Route::get('benachrichtigungen/eigene', [NotificationPreferenceController::class, 'index'])
    ->name('notifications.preferences');
Route::put('benachrichtigungen/eigene', [NotificationPreferenceController::class, 'update'])
    ->name('notifications.preferences.update');
Route::put('benachrichtigungen/eigene/ruhezeiten', [NotificationPreferenceController::class, 'quietHours'])
    ->name('notifications.preferences.quiet-hours');
Route::post('benachrichtigungen/eigene/abbestellen', [NotificationPreferenceController::class, 'subscription'])
    ->name('notifications.preferences.subscription');
// Die Bündelung für sich abschalten (A6) — die Gegenrichtung zur
// Einstellung am Projekt.
Route::post('benachrichtigungen/eigene/buendelung', [NotificationPreferenceController::class, 'digest'])
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
