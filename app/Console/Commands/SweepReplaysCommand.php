<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\Replay;
use App\Support\Ingest\Processing\Steps\RecordReplay;
use App\Support\Replays\ReplayStore;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Räumt abgelaufene Sitzungs-Aufzeichnungen weg (M3).
 *
 * Der Durchlauf ist keine Zugabe, sondern die zweite Hälfte der Zusage: eine
 * Aufbewahrungsfrist, die niemand durchsetzt, ist eine Absichtserklärung. Und
 * nirgends wiegt sie so schwer wie hier — eine Aufzeichnung ist der Bildschirm
 * eines Menschen, und sie ist zugleich das Schwerste, was diese Anwendung
 * speichert.
 *
 * **Die Frist gilt je Projekt** (`projects.replay_retention_days`), und ohne
 * eigene Angabe die des Betreibers (`config('replays.retention_days')`). Gerechnet
 * wird ab dem **letzten** Abschnitt und nicht ab dem Beginn: eine Sitzung, die
 * über Mitternacht lief, ist so alt wie ihr Ende und nicht wie ihr Anfang.
 *
 * Er räumt außerdem hinter gelöschten Projekten her. Deren Zeilen sind über den
 * Fremdschlüssel verschwunden, die Dateien nicht — eine Kaskade in der Datenbank
 * erreicht kein Laufwerk. Ohne diesen Schritt bliebe der schwerste Teil der
 * Anwendung für immer liegen, und zwar unauffindbar: der Weg zu den Dateien
 * führte über die Zeilen.
 */
class SweepReplaysCommand extends Command
{
    protected $signature = 'replays:sweep {--dry-run : Nur zeigen, was wegfiele}';

    protected $description = 'Löscht abgelaufene Sitzungs-Aufzeichnungen samt ihrer Bilddaten';

    /**
     * Wie viele Aufzeichnungen ein Durchlauf höchstens anfasst.
     *
     * Die Grenze schützt den Zeitplan: jede gelöschte Aufzeichnung ist ein
     * Ordner auf der Platte, und ein Durchlauf, der eine Million davon in einem
     * Zug wegräumt, blockiert den Minuten-Cron für Stunden. Was übrig bleibt,
     * holt der nächste Durchlauf — die Frist ist eine Obergrenze und kein
     * Termin auf die Minute.
     */
    public const BATCH = 500;

    public function handle(ReplayStore $store): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $removed = 0;
        $bytes = 0;

        foreach (Project::query()->select(['id', 'replay_retention_days'])->cursor() as $project) {
            $days = RecordReplay::retentionDays($project);
            $cutoff = CarbonImmutable::now()->subDays($days);

            $expired = Replay::query()
                ->where('project_id', $project->id)
                ->where('last_segment_at', '<', $cutoff)
                ->orderBy('last_segment_at')
                ->limit(self::BATCH)
                ->get();

            foreach ($expired as $replay) {
                $bytes += $replay->size_bytes;
                $removed++;

                if (! $dryRun) {
                    $store->forget($replay);
                }
            }
        }

        // Erst die Fristen, dann die Waisen. Andersherum wäre es zwar dasselbe
        // Ergebnis, aber der teurere Weg: das Durchsehen der Ordner lohnt sich
        // eher, nachdem die Fristen gegriffen haben.
        $orphaned = $dryRun
            ? 0
            : $store->forgetOrphanedProjects(Project::query()->pluck('id')->all());

        $this->info(sprintf(
            '%d Aufzeichnungen (%s) %s, %d Ordner gelöschter Projekte weggeräumt.',
            $removed,
            self::humanBytes($bytes),
            $dryRun ? 'wären weggefallen' : 'weggeräumt',
            $orphaned,
        ));

        return self::SUCCESS;
    }

    /**
     * Eine Größe, wie ein Mensch sie liest. Die Zahl steht in der Ausgabe eines
     * Durchlaufs, den jemand von Hand anstößt, um zu sehen, was er zurückbekommt
     * — und „4823749283 Bytes" beantwortet diese Frage nicht.
     */
    private static function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unit = 0;
        $value = (float) $bytes;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return sprintf('%.1f %s', $value, $units[$unit]);
    }
}
