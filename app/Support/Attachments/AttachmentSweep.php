<?php

namespace App\Support\Attachments;

use App\Models\EventAttachment;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Räumt die abgelaufenen Anhänge weg — je Projekt nach dessen eigener Frist.
 *
 * **Die Frist steht am Projekt und nicht in dieser Klasse.** Sie ist eine
 * Entscheidung über Daten und keine über Technik: ein Haus mit
 * Aufbewahrungspflichten stellt sie anders ein als eines ohne. Der Durchlauf
 * fragt sie deshalb je Projekt ab, statt eine Grenze für alle zu rechnen.
 *
 * **Gelöscht wird über die Ablage und nicht mit einem `delete()` über die
 * Tabelle.** Ein Löschen in der Datenbank ließe die Dateien auf dem Laufwerk
 * liegen — und genau das wäre der Fehler, den niemand bemerkt, bis die Platte
 * voll ist. Der Weg über {@see AttachmentStore::delete()} nimmt zugleich die
 * Rücksicht mit, die dort begründet ist: eine Datei, auf die noch eine zweite
 * Zeile zeigt, bleibt liegen.
 *
 * In Stücken und nicht auf einmal: ein Projekt mit einer Fehlerflut hat Millionen
 * Zeilen, und der Durchlauf soll nicht am Speicher scheitern.
 */
final class AttachmentSweep
{
    /** Wie viele Zeilen je Durchgang geholt werden. */
    private const CHUNK = 500;

    public function __construct(
        private readonly AttachmentStore $store,
    ) {}

    /**
     * @return array{projects: int, deleted: int, bytes: int}
     */
    public function run(): array
    {
        $projects = 0;
        $deleted = 0;
        $bytes = 0;

        Project::query()
            ->select(['id', 'attachment_retention_days'])
            ->orderBy('id')
            ->chunk(self::CHUNK, function (Collection $chunk) use (&$projects, &$deleted, &$bytes): void {
                foreach ($chunk as $project) {
                    $projects++;

                    [$count, $size] = $this->purge($project);

                    $deleted += $count;
                    $bytes += $size;
                }
            });

        return ['projects' => $projects, 'deleted' => $deleted, 'bytes' => $bytes];
    }

    /**
     * Die abgelaufenen Anhänge eines Projekts.
     *
     * @return array{int, int}
     */
    private function purge(Project $project): array
    {
        $retentionDays = max(1, (int) $project->attachment_retention_days);
        $cutoff = Carbon::now()->subDays($retentionDays);

        $count = 0;
        $bytes = 0;

        // `chunkById` und nicht `chunk`: die Zeilen fallen während des Durchlaufs
        // weg, und eine seitenweise Abfrage über einen kleiner werdenden Bestand
        // überspringt Zeilen. Der Aufsatzpunkt an der Kennung ist davon
        // unabhängig.
        EventAttachment::query()
            ->where('project_id', $project->id)
            ->where('received_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(self::CHUNK, function (Collection $attachments) use (&$count, &$bytes): void {
                foreach ($attachments as $attachment) {
                    $count++;

                    // Gezählt wird nur, was wirklich vom Laufwerk verschwunden ist:
                    // eine Datei, auf die noch eine zweite Zeile zeigt, bleibt
                    // liegen, und ein gescheitertes Löschen wird gemeldet
                    // ({@see AttachmentStore::delete()}). Alles davon als „frei"
                    // zu melden hieße, einen Betreiber suchen zu lassen, warum der
                    // Verbrauch trotz nächtlicher Meldung nicht sinkt.
                    if ($this->store->delete($attachment)) {
                        $bytes += $attachment->size;
                    }
                }
            });

        return [$count, $bytes];
    }
}
