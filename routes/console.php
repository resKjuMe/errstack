<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Zeitplan
|--------------------------------------------------------------------------
|
| Gestartet wird der Zeitplan mit `php artisan schedule:work` (Entwicklung,
| Teil von `composer dev`) bzw. auf dem Server mit einem Minuten-Cron auf
| `php artisan schedule:run`. `php artisan schedule:list` zeigt den Stand.
|
*/

// Erledigte Batches und alte Einträge der Fehlerablage aufräumen, damit die
// Tabellen nicht unbegrenzt wachsen.
Schedule::command('queue:prune-batches --hours=48')->daily();
Schedule::command('queue:prune-failed --hours=168')->daily();
