<?php

namespace App\Jobs;

use App\Enums\QueueName;
use App\Models\Release;
use App\Support\Integrations\GitHub\GitHubException;
use App\Support\Integrations\GitHub\GitHubReleaseCommits;
use App\Support\Releases\CommitImport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

/**
 * Holt die Commits einer Auslieferung über die Anbindung (X1).
 *
 * **Nie im Web-Request und nie im Schnittstellen-Aufruf.** Der Aufruf, der ihn
 * einreiht, ist der Abschluss einer Auslieferung — er steht in einer Pipeline,
 * die auf die Antwort wartet, und die Antwort darf nicht davon abhängen, wie
 * schnell GitHub heute ist. Ein Vergleich über 250 Commits samt Dateilisten ist
 * mehrere Sekunden Arbeit auf der anderen Seite.
 *
 * Die Auslieferung kommt als **Nummer** herein und nicht als Modell: zwischen
 * dem Einreihen und dem Ausführen liegt eine Warteschlange, und in der
 * Zwischenzeit kann dieselbe Pipeline ihre Commits längst selbst übergeben
 * haben. Geprüft wird der Stand von jetzt (siehe {@see GitHubReleaseCommits}).
 */
class FetchReleaseCommits implements ShouldQueue
{
    use Queueable;

    /**
     * Drei Versuche, weil der häufigste Fehlschlag vorübergeht: GitHub
     * antwortet gerade nicht, das Netz hakt, die Ratenbegrenzung greift. Der
     * eine Fall, der nicht vorbeigeht — der abgelehnte Zugang —, wird unten
     * abgefangen und beendet den Auftrag, statt ihn zweimal zu wiederholen.
     */
    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $releaseId)
    {
        $this->onQueue(QueueName::Default->value);
    }

    /**
     * Zwei Aufträge für dieselbe Auslieferung dürfen sich nicht überholen.
     *
     * Sie entstehen im Betrieb ohne Zutun: die Pipeline meldet die Version, der
     * Push kommt an, beide reihen ein. Ohne die Sperre liefen zwei Übernahmen
     * gleichzeitig auf dieselbe Zuordnungsliste — und die wird **gesetzt**, nicht
     * ergänzt (siehe {@see CommitImport}). Der zweite
     * Auftrag wird verworfen und nicht verzögert: er hätte nichts anderes zu tun
     * als der erste.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping((string) $this->releaseId))->dontRelease()];
    }

    public function handle(): void
    {
        $release = Release::query()->find($this->releaseId);

        if ($release === null) {
            return;
        }

        try {
            GitHubReleaseCommits::fetch($release);
        } catch (GitHubException $e) {
            if ($e->accessRejected) {
                // Der Zugang ist weg. Er ist an der Anbindung festgehalten und
                // steht damit in der Oberfläche; ein zweiter und dritter
                // Versuch käme genauso weit. Deshalb protokollieren und
                // beenden, statt die Ausnahme weiterzureichen.
                Log::warning('Commits einer Auslieferung nicht geholt: Zugang abgelehnt.', [
                    'release' => $this->releaseId,
                    'reason' => $e->getMessage(),
                ]);

                return;
            }

            throw $e;
        }
    }
}
