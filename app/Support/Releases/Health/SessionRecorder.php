<?php

namespace App\Support\Releases\Health;

use App\Models\Environment;
use App\Models\Project;
use App\Models\Release;
use App\Models\ReleaseSession;
use App\Models\ReleaseSessionCount;
use App\Models\ReleaseSessionUser;
use App\Support\Ingest\Processing\Steps\RecordSession;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Schreibt gemeldete Sitzungen in die Zahlen einer Version.
 *
 * Die eine Stelle, an der aus Meldungen Zähler werden — für beide Formate, das
 * einzelne `session`-Element und das gebündelte `sessions`-Element. Getrennt
 * vom Schritt der Verarbeitungskette
 * ({@see RecordSession}), weil der Schritt
 * über Zuständigkeit entscheidet („ist das eine Sitzung?") und diese Klasse
 * über Inhalt („was folgt daraus?"). Getrennt geprüft werden sie damit auch:
 * die Verrechnung der Zwischenstände braucht keine Envelope.
 *
 * **Die Zuordnung einer Sitzung steht mit ihrer ersten Meldung fest.** Version,
 * Umgebung, Zeitfenster und Nutzer werden beim Anlegen bestimmt und danach
 * nicht mehr angefasst; spätere Meldungen ändern nur noch den Ausgang. Das ist
 * keine Bequemlichkeit, sondern die einzige Auslegung, die sich verteidigen
 * lässt: eine Sitzung gehört zu der Version, in der sie **begonnen** hat. Ein
 * Absturz nach zwei Stunden zählt gegen die Version, die vor zwei Stunden
 * startete — sonst stünde in einem Zeitfenster ein Absturz mehr, als es dort
 * Sitzungen gibt.
 *
 * Die einzige Ausnahme ist die Nutzerkennung, und zwar nur in eine Richtung:
 * meldet ein SDK sie erst nachträglich, wird sie nachgetragen ({@see update()}).
 */
final class SessionRecorder
{
    /**
     * Nimmt eine einzelne Sitzung auf.
     *
     * @return bool `false`, wenn nichts erfasst wurde — dann ist die Meldung
     *              für die Release-Gesundheit gegenstandslos.
     */
    public function single(Project $project, SessionUpdate $update): bool
    {
        $release = Release::record($project, $update->version, $update->startedAt);

        if ($release === null) {
            return false;
        }

        $environment = $this->environment($project, $update->environment, $update->startedAt);

        // Die Sperre ist hier ausnahmsweise richtig: anders als bei den
        // reinen Zählern wird gelesen, verglichen und dann geschrieben — und
        // zwei Meldungen derselben Sitzung können gleichzeitig ankommen, weil
        // die Warteschlange nur je Meldung gegen Überschneidung sichert und
        // nicht je Sitzung. Ohne Sperre sähen beide denselben alten Ausgang
        // und verrechneten ihn zweimal.
        return DB::transaction(function () use ($project, $update, $release, $environment): bool {
            $session = $this->lock($project->id, $update->sid);

            if ($session === null) {
                return $this->start($project, $update, $release, $environment);
            }

            $this->update($session, $update);

            return true;
        });
    }

    /**
     * Nimmt ein Bündel bereits verrechneter Sitzungen auf.
     *
     * @return bool `false`, wenn nichts erfasst wurde.
     */
    public function batch(Project $project, SessionBatch $batch): bool
    {
        $first = $batch->buckets[0]->startedAt;
        $release = Release::record($project, $batch->version, $first);

        if ($release === null) {
            return false;
        }

        $environment = $this->environment($project, $batch->environment, $first);

        foreach ($batch->buckets as $bucket) {
            $this->add(
                $project->id,
                $release->id,
                $environment,
                $bucket->startedAt,
                ReleaseSessionUser::keyForIdentifier($bucket->userIdentifier),
                $bucket->tally,
            );
        }

        return true;
    }

    /**
     * Die erste Meldung einer Sitzung: Zeile anlegen und die Sitzung zählen.
     */
    private function start(Project $project, SessionUpdate $update, Release $release, string $environment): bool
    {
        $bucket = ReleaseSessionCount::bucket($update->startedAt);
        $userKey = ReleaseSessionUser::keyForIdentifier($update->userIdentifier);
        $now = now();

        $inserted = ReleaseSession::query()->insertOrIgnore([
            'project_id' => $project->id,
            'release_id' => $release->id,
            'environment' => $environment,
            'sid' => $update->sid,
            'user_key' => $userKey,
            'status' => $update->status->value,
            'error_count' => $update->errors,
            'seq' => $update->seq,
            'bucket_start' => $bucket,
            'started_at' => $update->startedAt,
            'last_seen_at' => $update->seenAt,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted === 0) {
            // Ein Rennen um dieselbe erste Meldung — der andere war schneller.
            // Seine Zeile ist maßgeblich, unsere Meldung ist ab jetzt eine
            // Folgemeldung. Ohne diesen Zweig zählte die Sitzung zweimal.
            $session = $this->lock($project->id, $update->sid);

            if ($session === null) {
                return false;
            }

            $this->update($session, $update);

            return true;
        }

        $this->add($project->id, $release->id, $environment, $bucket, $userKey, $update->tally());

        return true;
    }

    /**
     * Eine Folgemeldung: nur die Differenz zum bisherigen Ausgang zählt.
     */
    private function update(ReleaseSession $session, SessionUpdate $update): void
    {
        if ($update->seq < $session->seq) {
            // Eine überholte Meldung. Sie zu verrechnen hieße, einen bereits
            // gezählten Absturz mit einem älteren „läuft" zu widerrufen.
            return;
        }

        $previous = $session->tally();
        $current = $update->status->tally($update->errors);
        $userKey = $session->user_key ?? ReleaseSessionUser::keyForIdentifier($update->userIdentifier);

        $session->status = $update->status->value;
        $session->error_count = $update->errors;
        $session->seq = $update->seq;
        $session->user_key = $userKey;
        $session->last_seen_at = $update->seenAt->greaterThan($session->last_seen_at)
            ? $update->seenAt
            : $session->last_seen_at;
        $session->save();

        ReleaseSessionCount::apply(
            ReleaseSessionCount::keyFor($session->project_id, $session->release_id, $session->environment, $session->bucket_start),
            $current->minus($previous),
        );

        if ($userKey === null) {
            return;
        }

        ReleaseSessionUser::apply(
            ReleaseSessionUser::keyForUser($session->project_id, $session->release_id, $session->environment, $session->bucket_start, $userKey),
            // Trug die Sitzung bisher keine Nutzerkennung, hat die Nutzer-Zeile
            // den alten Ausgang nie gesehen — dann ist die ganze Sitzung neu für
            // sie und nicht bloß die Differenz.
            $session->wasChanged('user_key') ? $current : $current->minus($previous),
        );
    }

    /**
     * Schreibt eine Strichliste auf beide Zähler fort — die je Version und, wenn
     * ein Nutzer bekannt ist, die je Nutzer.
     *
     * Immer zusammen und nie einzeln: was hier auseinanderfiele, fiele in der
     * Auswertung auseinander. Eine Version mit Sitzungen, aber ohne Nutzer
     * sähe aus wie eine, die niemand benutzt.
     */
    private function add(
        int $projectId,
        int $releaseId,
        string $environment,
        DateTimeInterface $bucket,
        ?string $userKey,
        SessionTally $tally,
    ): void {
        ReleaseSessionCount::apply(
            ReleaseSessionCount::keyFor($projectId, $releaseId, $environment, $bucket),
            $tally,
        );

        if ($userKey === null) {
            return;
        }

        ReleaseSessionUser::apply(
            ReleaseSessionUser::keyForUser($projectId, $releaseId, $environment, $bucket, $userKey),
            $tally,
        );
    }

    /**
     * Die gespeicherte Sitzung, gegen gleichzeitige Änderung gesichert.
     */
    private function lock(int $projectId, string $sid): ?ReleaseSession
    {
        return ReleaseSession::query()
            ->where('project_id', $projectId)
            ->where('sid', $sid)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Die Umgebung, unter der die Sitzung geführt wird.
     *
     * Über {@see Environment::record()} und nicht als bloße Zeichenkette: eine
     * Umgebung, aus der ausschließlich Sitzungen kommen und keine Fehler, soll
     * trotzdem in der Filterleiste stehen. Sonst wäre eine gesunde Umgebung
     * genau die, die man nicht auswählen kann.
     */
    private function environment(Project $project, ?string $name, CarbonImmutable $seenAt): string
    {
        return Environment::record($project, $name, $seenAt)->name;
    }
}
