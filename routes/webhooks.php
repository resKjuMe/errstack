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
*/

use App\Http\Controllers\Webhooks\GitHubWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('hooks/github', GitHubWebhookController::class)->name('webhooks.github');
