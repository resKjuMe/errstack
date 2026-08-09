<?php

/*
|--------------------------------------------------------------------------
| Einladung annehmen
|--------------------------------------------------------------------------
|
| Included at the end of routes/web.php.
|
| Alles, was an einer Organisation eingerichtet wird — Stammdaten, Mitglieder,
| Teams, Einladungen aussprechen, Änderungsprotokoll, Datenschutz, Repositories —
| liegt seit U6 im Einstellungsbereich: routes/settings/organization.php.
|
| Hier bleibt nur der Weg herein. Er ist keine Einstellung, sondern ein Link aus
| einer Mail, und er gehört deshalb nicht hinter „Einstellungen": wer ihn öffnet,
| ist noch in keiner Organisation.
|
*/

use App\Http\Controllers\InvitationAcceptanceController;
use Illuminate\Support\Facades\Route;

// Bewusst ohne `verified`. Wer den Link aus der Mail hat, hat die Adresse
// nachweislich erhalten — und die Einladung gilt ohnehin nur für genau diese
// Adresse.
Route::middleware('auth')->group(function () {
    Route::get('einladung/{token}', [InvitationAcceptanceController::class, 'show'])
        ->name('invitations.show');
    Route::post('einladung/{token}', [InvitationAcceptanceController::class, 'store'])
        ->name('invitations.accept');
});
