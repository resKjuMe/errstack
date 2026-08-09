<?php

use App\Support\SelfMonitoring\ScheduleCheckIn;
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
$sweep = Schedule::command('crons:sweep')->everyMinute()->withoutOverlapping();

// Und dieser eine Job meldet sich seinerseits an die Selbstüberwachung.
//
// Er ist der Punkt, an dem die Kette sonst still abreißt: er stellt fest, dass
// ein fremder Cronjob ausgeblieben ist — bleibt er selbst aus, stellt das
// niemand fest. Ein Lebenszeichen dorthin, wo auch die fremden hingehen,
// schließt genau diese Lücke ({@see App\Support\SelfMonitoring\ScheduleCheckIn}).
ScheduleCheckIn::attach($sweep);

// Die fälligen Erreichbarkeits-Prüfungen anstoßen (M2).
//
// Der vierte Fall derselben Art — und der einzige, der von **außen** schaut.
// Die drei anderen laufen in der Anwendung und stellen fest, dass etwas
// ausbleibt; dieser stellt fest, dass die Anwendung selbst nicht mehr da ist.
// Ein Totalausfall erzeugt keine Fehlermeldung, weil nichts mehr läuft, was
// eine schicken könnte.
//
// Minütlich, weil der kürzeste einstellbare Takt eine Minute ist
// ({@see App\Models\UptimeMonitor::MINIMUM_INTERVAL_SECONDS}) — gröber wäre die
// Einstellung eine Zusage, die niemand hält. `withoutOverlapping` gegen das
// Auflaufen; geprüft wird ohnehin nicht hier, sondern in der Warteschlange.
Schedule::command('uptime:sweep')->everyMinute()->withoutOverlapping();

// Schwellwert-Alarme auf Kennzahlen auswerten (A3).
//
// Dieselbe Begründung wie beim Cronjob-Sweep: eine Kennzahl, die schlechter
// wird, meldet sich nicht von selbst. `withoutOverlapping` nicht wegen der
// Richtigkeit — der Zustandswechsel ist eine bedingte Anweisung und gegen
// doppelte Läufe abgesichert —, sondern gegen das Auflaufen: ein Durchlauf,
// der länger als eine Minute braucht, würde sonst vom nächsten überholt.
Schedule::command('alerts:sweep')->everyMinute()->withoutOverlapping();

// Nachsehen, ob die eigene Verarbeitung mitkommt (O5).
//
// Der dritte Fall derselben Art: ein Rückstand meldet sich nicht von selbst.
// Ein Fehler meldet sich, ein Ausfall fällt auf — eine Warteschlange, die
// volläuft, sieht von außen aus wie Betrieb, bis Nutzer fragen, warum ihre
// Fehler fehlen.
//
// Minütlich, weil die Frist bis zur Warnung in Minuten gerechnet wird
// ({@see App\Support\Operations\BacklogWatch}) und ein gröberer Takt sie
// verwässern würde. `withoutOverlapping` gegen das Auflaufen: die Prüfung liest
// den Rückstand, und genau der macht Abfragen langsam.
Schedule::command('ops:watch')->everyMinute()->withoutOverlapping();

// Die Wichtigkeit der Fehler fortschreiben und eskalierte Stummschaltungen
// erkennen (S11).
//
// Alle fünfzehn Minuten und nicht minütlich: die Ableitung liest den Verlauf von
// zwei Tagen, und der ändert sich in einer Minute um nichts, was eine Einordnung
// umwerfen würde. Die Eskalation misst gegen die zuletzt **vollständige** Stunde
// ({@see App\Support\Issues\IssuePrioritySweep}) — häufiger nachzusehen als
// viermal in der Stunde bringt deshalb keine Meldung früher, nur mehr Abfragen.
//
// `withoutOverlapping`, weil ein Durchlauf über viele Fehler länger dauern kann
// als der Abstand zum nächsten: zwei gleichzeitige Durchläufe würden dieselbe
// Eskalation zweimal melden.
Schedule::command('issues:prioritize')->everyFifteenMinutes()->withoutOverlapping();

// Brüche in den Antwortzeiten suchen (PF7).
//
// Stündlich, weil die Rechnung auf Stundenfenstern steht
// ({@see App\Support\Performance\Trends\TrendSeries}): häufiger nachzusehen
// legte denselben Verlauf noch einmal aus, ohne dass ein neues Fenster
// dazugekommen wäre. Zur vollen Stunde plus fünf Minuten, damit die
// Vorberechnung der letzten Minute sicher geschrieben ist — das Fenster, das
// gerade zu Ende ging, ist das interessanteste.
//
// `withoutOverlapping`, weil der Durchlauf über viele Projekte länger als eine
// Stunde brauchen kann: zwei gleichzeitige Läufe würden dieselben Brüche
// feststellen und um dieselbe Zeile ringen.
Schedule::command('performance:trends')->hourlyAt(5)->withoutOverlapping();

// Fällige Sammelnachrichten der Bündelung verschicken (A6).
//
// Minütlich, weil das Fenster in Minuten eingestellt wird: ein gröberer Takt
// wäre eine zweite, unsichtbare Wartezeit oben drauf — wer fünf Minuten
// einstellt, bekäme dann bis zu zehn. `withoutOverlapping` gegen den doppelten
// Versand: zwei gleichzeitige Durchläufe könnten denselben Korb greifen, bevor
// der erste ihn abgeräumt hat.
Schedule::command('notifications:flush-digests')->everyMinute()->withoutOverlapping();

// Der Wochenbericht je Projekt (A6).
//
// Montagmorgen und nicht Sonntagnacht: der Bericht ist zum Lesen da, und
// gelesen wird er am Anfang der Woche. Berichtet wird die abgeschlossene
// Vorwoche ({@see App\Console\Commands\SendWeeklyReportsCommand}).
Schedule::command('reports:weekly')->weeklyOn(1, '08:00');
