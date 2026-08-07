<?php

/*
|--------------------------------------------------------------------------
| Organisationen, Teams und Einladungen
|--------------------------------------------------------------------------
|
| Included at the end of routes/web.php. Die Rechteprüfung steckt in den
| Policies (App\Policies), nicht in einer Middleware — die Routen selbst
| verlangen nur ein angemeldetes Konto.
|
*/

use App\Http\Controllers\InvitationAcceptanceController;
use App\Http\Controllers\MembershipController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\OrganizationInvitationController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TeamMemberController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('organisationen', [OrganizationController::class, 'index'])
        ->name('organizations.index');
    Route::post('organisationen', [OrganizationController::class, 'store'])
        ->name('organizations.store');

    Route::get('organisationen/{organization}', [OrganizationController::class, 'show'])
        ->name('organizations.show');
    Route::patch('organisationen/{organization}', [OrganizationController::class, 'update'])
        ->name('organizations.update');
    Route::delete('organisationen/{organization}', [OrganizationController::class, 'destroy'])
        ->name('organizations.destroy');
    Route::post('organisationen/{organization}/wechseln', [OrganizationController::class, 'switch'])
        ->name('organizations.switch');

    Route::post('organisationen/{organization}/einladungen', [OrganizationInvitationController::class, 'store'])
        ->name('organizations.invitations.store');
    Route::patch('einladungen/{invitation}', [OrganizationInvitationController::class, 'update'])
        ->name('invitations.update');
    Route::delete('einladungen/{invitation}', [OrganizationInvitationController::class, 'destroy'])
        ->name('invitations.destroy');

    Route::patch('mitgliedschaften/{membership}', [MembershipController::class, 'update'])
        ->name('memberships.update');
    Route::delete('mitgliedschaften/{membership}', [MembershipController::class, 'destroy'])
        ->name('memberships.destroy');

    Route::post('organisationen/{organization}/teams', [TeamController::class, 'store'])
        ->name('teams.store');
    Route::get('teams/{team}', [TeamController::class, 'show'])
        ->name('teams.show');
    Route::patch('teams/{team}', [TeamController::class, 'update'])
        ->name('teams.update');
    Route::delete('teams/{team}', [TeamController::class, 'destroy'])
        ->name('teams.destroy');

    Route::post('teams/{team}/mitglieder', [TeamMemberController::class, 'store'])
        ->name('teams.members.store');
    Route::delete('teams/{team}/mitglieder/{user}', [TeamMemberController::class, 'destroy'])
        ->name('teams.members.destroy');
});

// Einladung annehmen: bewusst ohne `verified`. Wer den Link aus der Mail hat,
// hat die Adresse nachweislich erhalten — und die Einladung gilt ohnehin nur
// für genau diese Adresse.
Route::middleware('auth')->group(function () {
    Route::get('einladung/{token}', [InvitationAcceptanceController::class, 'show'])
        ->name('invitations.show');
    Route::post('einladung/{token}', [InvitationAcceptanceController::class, 'store'])
        ->name('invitations.accept');
});
