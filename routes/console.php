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

// Verpasste und hängende Cronjob-Ausführungen feststellen (M1).
//
// Das ist die einzige Stelle, an der ein *ausgebliebener* Job überhaupt
// auffallen kann — alles andere geschieht, weil sich jemand meldet. Läuft der
// Zeitplan nicht, meldet die Überwachung still nichts mehr. `withoutOverlapping`,
// damit ein langsamer Durchlauf den nächsten nicht überholt und derselbe Termin
// zweimal als verpasst gilt.
Schedule::command('crons:sweep')->everyMinute()->withoutOverlapping();
