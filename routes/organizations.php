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

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\InvitationAcceptanceController;
use App\Http\Controllers\MembershipController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\OrganizationInvitationController;
use App\Http\Controllers\OrganizationPrivacyController;
use App\Http\Controllers\OrganizationQuotaController;
use App\Http\Controllers\RepositoryController;
use App\Http\Controllers\ScrubRuleController;
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

    // Änderungsprotokoll. Der Export hängt an einer eigenen Route statt an
    // einem Parameter der Ansicht, damit er sich verlinken lässt — er nimmt
    // dieselben Filter entgegen und gibt genau die angezeigte Auswahl aus.
    Route::get('organisationen/{organization}/protokoll', [AuditLogController::class, 'index'])
        ->name('organizations.audit-log.index');
    Route::get('organisationen/{organization}/protokoll/export', [AuditLogController::class, 'export'])
        ->name('organizations.audit-log.export');

    // Organisationsweiter Datenschutz: Regeln, die für alle Projekte gelten.
    // Geändert und gelöscht werden sie über `scrub-rules.*` weiter unten — die
    // Regel weiß selbst, zu welcher Ebene sie gehört.
    // Kontingente der Organisation (O1): sie gelten über allen Projekten
    // zusammen. Ansehen darf jedes Mitglied, ändern die Verwaltung.
    Route::get('organisationen/{organization}/kontingente', [OrganizationQuotaController::class, 'index'])
        ->name('organizations.quotas.index');
    Route::patch('organisationen/{organization}/kontingente', [OrganizationQuotaController::class, 'update'])
        ->name('organizations.quotas.update');

    Route::get('organisationen/{organization}/datenschutz', [OrganizationPrivacyController::class, 'index'])
        ->name('organizations.privacy.index');
    Route::post('organisationen/{organization}/datenschutz/regeln', [ScrubRuleController::class, 'store'])
        ->name('organizations.privacy.rules.store');

    // Eine Regel ändern und löschen, ohne die Ebene erneut in der Adresse zu
    // nennen — wie bei Einladungen und Mitgliedschaften.
    Route::patch('datenschutz-regeln/{scrub_rule}', [ScrubRuleController::class, 'update'])
        ->name('scrub-rules.update');
    Route::delete('datenschutz-regeln/{scrub_rule}', [ScrubRuleController::class, 'destroy'])
        ->name('scrub-rules.destroy');

    // Verbundene Repositories (R2): woher der Code dieser Organisation kommt.
    // Ansehen darf jedes Mitglied, verbinden und lösen die Verwaltung — die
    // Prüfung steht im Controller.
    Route::get('organisationen/{organization}/repositories', [RepositoryController::class, 'index'])
        ->name('organizations.repositories.index');
    Route::post('organisationen/{organization}/repositories', [RepositoryController::class, 'store'])
        ->name('organizations.repositories.store');

    // Gelöst wird ohne diesen Vorbau, wie bei Einladungen und Regeln: das
    // Repository weiß selbst, zu welcher Organisation es gehört.
    Route::delete('repositories/{repository}', [RepositoryController::class, 'destroy'])
        ->name('repositories.destroy');

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
