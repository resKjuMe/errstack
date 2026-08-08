<?php

namespace App\Support\Digests;

use App\Jobs\DeliverDigest;
use App\Jobs\DeliverPersonalNotification;
use App\Models\NotificationDigestEntry;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Leert die Wartekörbe, deren Zeit gekommen ist.
 *
 * Ein Korb ist fällig, wenn eine von zwei Bedingungen zutrifft:
 *
 *   - **Das Fenster ist abgelaufen** — gerechnet ab der *ältesten* wartenden
 *     Meldung und nicht ab der jüngsten. Andernfalls verlängerte jede neue
 *     Meldung die Wartezeit der bereits liegenden, und ein Fehler, der im
 *     Sekundentakt auftritt, hielte die Sammelnachricht für immer zurück.
 *   - **Die Höchstzahl ist erreicht** — dann geht sie sofort hinaus. Ein Korb,
 *     der unbegrenzt wächst, ergibt am Ende eine Mail, die niemand liest, und
 *     genau davor soll die Bündelung schützen.
 *
 * Die **Mindestzahl** entscheidet nicht über das Warten, sondern darüber, was
 * am Ende hinausgeht: liegen weniger Meldungen im Korb, als sich zu bündeln
 * lohnt, gehen sie einzeln hinaus. Eine „Sammelnachricht" mit einem einzigen
 * Eintrag wäre eine Meldung mit Umweg — sie hätte alle Nachteile der
 * Verzögerung und keinen ihrer Vorteile.
 *
 * Was hinausgeht, entscheidet auch hier nicht dieser Durchlauf: er reicht an
 * die Warteschlange weiter, und dort werden die persönlichen Einstellungen ein
 * letztes Mal geprüft ({@see DeliverDigest}).
 */
final class DigestFlusher
{
    /**
     * @return int Wie viele Körbe geleert wurden.
     */
    public function flush(?CarbonImmutable $now = null): int
    {
        $now = $now ?? CarbonImmutable::instance(Date::now());
        $flushed = 0;

        foreach ($this->buckets() as $bucket) {
            $project = Project::query()->find($bucket->project_id);

            if ($project === null) {
                // Ohne Projekt gibt es kein Fenster, an dem sich der Korb
                // ausrichten könnte. Er würde sonst für immer liegen bleiben.
                $this->discard($bucket);

                continue;
            }

            if (! $this->isDue($bucket, $project, $now)) {
                continue;
            }

            $this->release($bucket, $project);
            $flushed++;
        }

        return $flushed;
    }

    /**
     * Die Körbe mit Alter und Füllstand — eine Abfrage statt einer je Nutzer.
     *
     * @return list<object{user_id: int, project_id: int|null, event_type: string, total: int, oldest: string}>
     */
    private function buckets(): array
    {
        // Über den Query Builder und nicht über das Modell: die Zeilen sind
        // Gruppen und keine Einträge — als Modell gelesen liefe der Anlass
        // durch den Enum-Cast, und `count(*)` gäbe es dort ohnehin nicht.
        return DB::table((new NotificationDigestEntry)->getTable())
            ->selectRaw('user_id, project_id, event_type, count(*) as total, min(created_at) as oldest')
            ->groupBy('user_id', 'project_id', 'event_type')
            ->get()
            ->map(static fn ($row): object => (object) [
                'user_id' => (int) $row->user_id,
                'project_id' => $row->project_id === null ? null : (int) $row->project_id,
                'event_type' => (string) $row->event_type,
                'total' => (int) $row->total,
                'oldest' => (string) $row->oldest,
            ])
            ->values()
            ->all();
    }

    private function isDue(object $bucket, Project $project, CarbonImmutable $now): bool
    {
        if ($bucket->total >= $project->digest_max_events) {
            return true;
        }

        return CarbonImmutable::parse($bucket->oldest)
            ->addMinutes($project->digest_window_minutes)
            ->lessThanOrEqualTo($now);
    }

    /**
     * Schickt den Inhalt eines fälligen Korbs auf den Weg und räumt ihn ab.
     */
    private function release(object $bucket, Project $project): void
    {
        $entries = $this->take($bucket, $project->digest_max_events);
        $first = $entries->first();

        if ($first === null) {
            return;
        }

        $user = User::query()->find($bucket->user_id);

        if ($user === null) {
            $this->delete($entries);

            return;
        }

        $organization = $project->organization;
        $messages = $entries->map(
            static fn (NotificationDigestEntry $entry) => $entry->message(),
        )->values()->all();

        if (count($messages) < $project->digest_min_events) {
            foreach ($messages as $message) {
                DeliverPersonalNotification::dispatch($user, $message, $first->event_type, $project, $organization);
            }
        } else {
            DeliverDigest::dispatch($user, $project, $first->event_type, $messages);
        }

        $this->delete($entries);
    }

    /**
     * @return Collection<int, NotificationDigestEntry>
     */
    private function take(object $bucket, int $limit): Collection
    {
        return $this->query($bucket)
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();
    }

    private function discard(object $bucket): void
    {
        $this->query($bucket)->delete();
    }

    /**
     * @return Builder<NotificationDigestEntry>
     */
    private function query(object $bucket): Builder
    {
        return NotificationDigestEntry::query()
            ->where('user_id', $bucket->user_id)
            ->where('event_type', $bucket->event_type)
            ->when(
                $bucket->project_id === null,
                fn ($query) => $query->whereNull('project_id'),
                fn ($query) => $query->where('project_id', $bucket->project_id),
            );
    }

    /**
     * @param  Collection<int, NotificationDigestEntry>  $entries
     */
    private function delete(Collection $entries): void
    {
        $ids = $entries->pluck('id')->all();

        if ($ids === []) {
            return;
        }

        DB::table((new NotificationDigestEntry)->getTable())->whereIn('id', $ids)->delete();
    }
}
