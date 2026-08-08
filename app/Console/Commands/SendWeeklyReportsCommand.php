<?php

namespace App\Console\Commands;

use App\Jobs\SendWeeklyReport;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;

/**
 * Der wöchentliche Bericht je Projekt (A6).
 *
 * **Berichtet wird die abgeschlossene Woche**, nicht die laufende: ein Bericht
 * über eine Woche, die noch läuft, verglichen mit einer, die es nicht mehr tut,
 * zeigt jedes Mal einen Rückgang — und wäre damit die verlässlichste Art, einen
 * Trend falsch darzustellen.
 *
 * Der Befehl reiht nur ein. Gerechnet und verschickt wird in der Warteschlange
 * ({@see SendWeeklyReport}): ein Zeitplan-Durchlauf, der für hundert Projekte
 * die Zähler einer Woche zusammenzieht, wäre der eine Minutentakt, der nicht
 * mehr rechtzeitig fertig wird.
 */
class SendWeeklyReportsCommand extends Command
{
    protected $signature = 'reports:weekly {--week= : Beginn der berichteten Woche (Y-m-d), Vorgabe ist die vergangene}';

    protected $description = 'Verschickt den Wochenbericht je Projekt';

    public function handle(): int
    {
        $week = $this->week();
        $queued = 0;

        Project::query()->orderBy('id')->chunkById(100, function ($projects) use ($week, &$queued): void {
            foreach ($projects as $project) {
                SendWeeklyReport::dispatch($project, $week->format('Y-m-d'));
                $queued++;
            }
        });

        $this->info(sprintf('%d Wochenberichte ab %s eingereiht.', $queued, $week->format('Y-m-d')));

        return self::SUCCESS;
    }

    private function week(): CarbonImmutable
    {
        $option = $this->option('week');

        if (is_string($option) && $option !== '') {
            return CarbonImmutable::parse($option)->startOfDay();
        }

        return CarbonImmutable::instance(Date::now())->startOfWeek()->subWeek();
    }
}
