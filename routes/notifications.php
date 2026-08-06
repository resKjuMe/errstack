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
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
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
