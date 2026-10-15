<?php

/*
|--------------------------------------------------------------------------
| Eingehende Meldungen der Anbieter (X1)
|--------------------------------------------------------------------------
|
| Eingebunden von routes/api.php und damit unter `/api/…` erreichbar — und
| damit ohne Sitzung, ohne CSRF-Prüfung und ohne Anmeldung. Das ist hier keine
| Nachlässigkeit, sondern die Voraussetzung: der Anrufer ist GitHub, und der
| bringt weder Cookie noch Token mit. Ausgewiesen wird er über die Unterschrift
| des Rumpfes (App\Support\Integrations\GitHub\GitHubWebhook::verify) — ohne
| eingerichtetes Geheimnis wird jede Meldung abgewiesen.
|
| Vor routes/ingest.php eingebunden, damit die Adresse nicht in deren
| `{project}`-Muster gerät. Zusätzlich abgesichert ist das dort über
| `whereNumber`; die Reihenfolge ist der Gürtel zu den Hosenträgern.
|
| Bewusst **keine** Organisation in der Adresse: die Rückadresse muss bei GitHub
| je Repository eingetragen werden und wäre sonst je Organisation eine andere.
| Wozu ein Ereignis gehört, sagt das Repository darin.
|
| Die Ticket-Systeme (X4) gehen den umgekehrten Weg: dort steht ein Geheimnis in
| der Adresse, und **es** sagt, wozu die Meldung gehört. Der Grund ist keine
| Vorliebe — Jira Cloud unterschreibt eine über die Schnittstelle eingetragene
| Rückadresse nicht, und Linears Unterschrift hängt an einem Geheimnis, das
| drüben entsteht. Ohne das Geheimnis in der Adresse wäre der Eingang offen:
| „Vorgang OPS-42 ist erledigt" setzt einen Fehler hier auf erledigt.
|
*/

use App\Http\Controllers\Webhooks\GitHubWebhookController;
use App\Http\Controllers\Webhooks\TicketWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('hooks/github', GitHubWebhookController::class)->name('webhooks.github');

// Eine Adresse je Anbieter, und der Anbieter steht im Pfad: beim Einrichten ist
// das die Stelle, an der man nichts verwechseln kann — und sie verhindert, dass
// eine Jira-Nutzlast über die Linear-Adresse hereinkommt und nach Feldern
// gelesen wird, die es dort nicht gibt.
//
// Ohne `whereIn`-Aufzählung: welche Anbieter es gibt, steht im Enum, und ein
// unbekannter wird im Controller wie ein falsches Geheimnis behandelt — mit `401`
// und ohne Auskunft darüber, was nicht gepasst hat. Eine Bedingung hier machte
// daraus ein `404` und damit genau die Unterscheidung, die niemand bekommen soll.
Route::post('hooks/tickets/{provider}/{token}', TicketWebhookController::class)
    ->name('webhooks.tickets');
