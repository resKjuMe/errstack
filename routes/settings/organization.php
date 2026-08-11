<?php

/*
|--------------------------------------------------------------------------
| Einstellungen: Organisation, Teams, Einladungen, Datenschutz, Repositories
|--------------------------------------------------------------------------
|
| Included aus routes/settings.php (Präfix `einstellungen`, Middleware `auth`
| und `verified`). Die Rechteprüfung steckt unverändert in den Policies
| (App\Policies), nicht in einer Middleware.
|
| Bis U6 lagen diese Adressen direkt unter `/organisationen/…` — gleichrangig
| neben den Fachseiten derselben Organisation. Sie liegen jetzt darunter im
| Einstellungsbereich; die Fachseiten (`/organisationen/{slug}/fehler`, …) sind
| davon unberührt.
|
| Das Annehmen einer Einladung ist bewusst nicht mitgezogen: es ist keine
| Einstellung, sondern der Weg herein — und der führt über einen Link aus einer
| Mail. Es steht weiterhin in routes/organizations.php.
|
*/

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\GitHubIntegrationController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\IntegrationRepositoryController;
use App\Http\Controllers\MembershipController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\OrganizationInvitationController;
use App\Http\Controllers\OrganizationPrivacyController;
use App\Http\Controllers\OrganizationQuotaController;
use App\Http\Controllers\RepositoryController;
use App\Http\Controllers\ScrubRuleController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TeamMemberController;
use App\Http\Controllers\TicketIntegrationController;
use App\Http\Controllers\TicketTargetController;
use Illuminate\Support\Facades\Route;

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

// Die Anbindungen an Anbieter (X1). Ansehen darf jedes Mitglied — „ist die
// Anbindung kaputt?" ist die Frage, die aufkommt, wenn an einer Auslieferung
// die Commits fehlen. Verbinden und lösen darf die Verwaltung; die Prüfung
// steht in den Controllern.
Route::get('organisationen/{organization}/anbindungen', [IntegrationController::class, 'index'])
    ->name('organizations.integrations.index');
Route::delete('organisationen/{organization}/anbindungen/{integration}', [IntegrationController::class, 'destroy'])
    ->name('organizations.integrations.destroy');

// Die Repository-Auswahl einer Anbindung. Sie liegt unter der Organisation und
// nicht unter der Anbindung: es gibt je Anbieter genau eine, und ihre Kennung
// in der Adresse wäre eine Angabe, die nichts unterscheidet.
Route::get('organisationen/{organization}/anbindungen/github/repositories', [IntegrationRepositoryController::class, 'index'])
    ->name('organizations.integrations.repositories.index');
Route::post('organisationen/{organization}/anbindungen/github/repositories', [IntegrationRepositoryController::class, 'store'])
    ->name('organizations.integrations.repositories.store');

// Der Weg zu GitHub und zurück. Die Rückkehr trägt **keine** Organisation in
// der Adresse: sie muss bei GitHub fest hinterlegt werden, und eine, die je
// Organisation anders aussieht, ließe sich dort nicht eintragen. Welche gemeint
// war, steht im `state`-Wert in der Sitzung.
Route::get('organisationen/{organization}/anbindungen/github/anmelden', [GitHubIntegrationController::class, 'redirect'])
    ->name('organizations.integrations.github.redirect');
Route::get('anbindungen/github/rueckkehr', [GitHubIntegrationController::class, 'callback'])
    ->name('integrations.github.callback');

// Die Ticket-Systeme (X4). Verbunden wird mit einem Formular und nicht über eine
// Weiterleitung — ein API-Token braucht keine registrierte App, und bis es die
// gibt, ist das der Weg, der sofort funktioniert. Der Anbieter steht im Pfad und
// nicht in der Nutzlast: eine Adresse je Anbieter ist die Stelle, an der man beim
// Einrichten nichts verwechseln kann.
//
// Das Ändern und das Erneuern der Rückadresse tragen dagegen die **Anbindung** in
// der Adresse und nicht den Anbieter: beide setzen voraus, dass es sie gibt, und
// eine Kennung, die auf eine fremde Organisation zeigt, ist so schneller zu
// erkennen als eine Aufzählung, die überall passt.
//
// **Keine `whereIn`-Aufzählung der Anbieter.** Welche es gibt, steht im Enum
// (App\Enums\IntegrationProvider), und die Controller entscheiden es dort — eine
// zweite Liste hier wäre nicht nur doppelt, sie antwortet auch falsch: ein
// `POST …/tickets/trello`, das an der Bedingung vorbeigeht, fällt auf das
// PATCH-Muster darunter und ergibt `405` („falsche Methode") statt `404` („gibt
// es nicht").
Route::post('organisationen/{organization}/anbindungen/tickets/{provider}', [TicketIntegrationController::class, 'store'])
    ->name('organizations.integrations.tickets.store');
Route::patch('organisationen/{organization}/anbindungen/tickets/{integration}', [TicketIntegrationController::class, 'update'])
    ->name('organizations.integrations.tickets.update');
Route::post('organisationen/{organization}/anbindungen/tickets/{integration}/rueckadresse', [TicketIntegrationController::class, 'rotate'])
    ->name('organizations.integrations.tickets.rotate');

// Wohin ein Ticket gelegt werden kann — Jira-Projekte, Linear-Teams. Auf
// Anforderung und als JSON, weil es ein Aufruf über das Netz ist: weder die
// Einstellungs- noch die Fehlerseite soll sich deshalb verzögern.
Route::get('organisationen/{organization}/anbindungen/tickets/{provider}/ziele', [TicketTargetController::class, 'index'])
    ->name('organizations.integrations.tickets.targets');

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
