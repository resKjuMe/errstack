<?php

namespace App\Support\Replays;

use App\Models\Event;
use App\Models\Replay;
use App\Models\ReplayError;
use Illuminate\Support\Collection;

/**
 * Was die Oberfläche von einer Aufzeichnung zu sehen bekommt.
 *
 * Die Umrechnung steht hier und nicht im Controller, weil sie an drei Stellen
 * gebraucht wird — Liste, Abspielseite und der Weg von einem Fehler her — und
 * überall dieselbe sein muss. Eine Liste, in der „3 Fehler" steht, und eine
 * Detailseite, die vier zeigt, wäre kein Anzeigefehler, sondern zwei
 * Rechenwege.
 *
 * Zeitangaben gehen als **Abstand in Millisekunden** hinaus und nicht als Uhrzeit.
 * Der Abspieler kennt nur diesen Abstand, die Zeitleiste rechnet damit ohne
 * Zeitzone, und ein Sprung zu einem Fehler ist derselbe Wert, den beide ohnehin
 * verlangen.
 */
final class ReplayData
{
    /**
     * Eine Zeile der Liste.
     *
     * @return array<string, mixed>
     */
    public static function row(Replay $replay): array
    {
        return [
            'id' => $replay->id,
            'replayId' => $replay->replay_id,
            'href' => route('replays.show', $replay),
            'project' => $replay->project === null ? null : [
                'slug' => $replay->project->slug,
                'name' => $replay->project->name,
            ],
            'startedAt' => $replay->started_at->toIso8601String(),
            'durationMs' => $replay->duration_ms,
            'segmentCount' => $replay->segment_count,
            'eventCount' => $replay->event_count,
            'errorCount' => $replay->error_count,
            'url' => $replay->url,
            'urlCount' => count($replay->urls ?? []),
            'environment' => $replay->environment,
            'release' => $replay->release,
            'browser' => $replay->browser,
            'os' => $replay->os,
            'device' => $replay->device,
            'user' => self::user($replay),
            'masked' => $replay->masked,
            // „Läuft noch" ist eine Aussage über **jetzt** und wird deshalb hier
            // gerechnet und nicht gespeichert: eine Sitzung, die vor einer
            // Stunde still wurde, war beim letzten Abschnitt noch offen.
            'ongoing' => ! $replay->hasEnded(),
        ];
    }

    /**
     * Alles, was die Abspielseite braucht — außer den Bilddaten.
     *
     * Die kommen getrennt nach ({@see ReplayController::data()}) und stehen
     * bewusst nicht in dieser Antwort: es sind Megabyte, und eine Seite, die
     * erst nach ihnen erscheint, wäre eine Seite, bei der man nicht weiß, ob sie
     * lädt oder hängt.
     *
     * @return array<string, mixed>
     */
    public static function detail(Replay $replay): array
    {
        return self::row($replay) + [
            'urls' => $replay->urls ?? [],
            'sdk' => $replay->sdk,
            'platform' => $replay->platform,
            'dist' => $replay->dist,
            'sizeBytes' => $replay->size_bytes,
            'lastSegmentAt' => $replay->last_segment_at->toIso8601String(),
        ];
    }

    /**
     * Die Sprungmarken: welcher Fehler wann passiert ist.
     *
     * **Ein Verweis darf ins Leere zeigen.** Die Verknüpfung führt eine
     * Ereignis-Nummer und keinen Fremdschlüssel (siehe Migration), und die
     * Aufbewahrungsfristen beider Bestände sind verschieden. Ein Fehler, der
     * inzwischen weggeräumt ist, verschwindet deshalb aus dieser Liste, statt
     * die Seite mit einem toten Verweis zu füllen — die Marke ohne Ziel wäre
     * eine Einladung zu einem Klick, der nirgendwo hinführt.
     *
     * @param  Collection<int, ReplayError>  $links
     * @return list<array<string, mixed>>
     */
    public static function errors(Replay $replay, Collection $links): array
    {
        if ($links->isEmpty()) {
            return [];
        }

        /** @var Collection<string, Event> $events */
        $events = Event::query()
            ->where('project_id', $replay->project_id)
            ->whereIn('event_id', $links->pluck('event_id')->all())
            ->with('group.issue:id,title,culprit')
            ->get()
            ->keyBy('event_id');

        $startMs = $replay->started_at->getTimestampMs();
        $marks = [];

        foreach ($links as $link) {
            $event = $events->get($link->event_id);

            if ($event === null) {
                continue;
            }

            $issue = $event->group?->issue;
            $occurredAt = $link->occurred_at ?? $event->occurred_at->toImmutable();

            $marks[] = [
                'eventId' => $event->event_id,
                // `->` und nicht `?->`: das Null-Zusammenfassen deckt den
                // fehlenden Eintrag mit ab, und ein zusätzliches `?->` wäre
                // eine zweite Absicherung für denselben Fall.
                'title' => $issue->title ?? $event->title ?? $event->event_id,
                'culprit' => $issue->culprit ?? $event->culprit,
                'level' => $event->level->value,
                'occurredAt' => $occurredAt->toIso8601String(),
                // Der Abstand zum Beginn — der Wert, mit dem der Abspieler
                // springt. Negativ kann er werden, wenn der Fehler vor dem
                // ersten Abschnitt passierte (die Aufnahme lief da schon, ihr
                // erster Abschnitt kam nur später an). Dann liegt die Marke am
                // Anfang statt außerhalb.
                'offsetMs' => max(0, $occurredAt->getTimestampMs() - $startMs),
                'href' => $issue === null ? null : route('issues.events.show', [$issue, $event]),
            ];
        }

        usort($marks, static fn (array $a, array $b): int => $a['offsetMs'] <=> $b['offsetMs']);

        return $marks;
    }

    /**
     * Die Aufzeichnungen, in denen dieser Fehler passiert ist — der Weg von der
     * Fehlerseite zum Film.
     *
     * Eine Liste und keine einzelne: derselbe Fehler kann in mehreren Sitzungen
     * aufgetreten sein, wenn dieselbe Nummer erneut gemeldet wurde. Der
     * Regelfall ist trotzdem genau eine — die Anzeige rechnet mit beidem.
     *
     * Gezeigt werden nur Aufzeichnungen mit Bilddaten ({@see Replay::scopePlayable()}).
     * Eine Verknüpfung kann auf eine Sitzung zeigen, deren Aufnahme nie ankam;
     * ein Verweis darauf wäre die Einladung zu einem leeren Abspieler.
     *
     * @return list<array<string, mixed>>
     */
    public static function forEvent(Event $event): array
    {
        $replayIds = ReplayError::query()
            ->where('project_id', $event->project_id)
            ->where('event_id', $event->event_id)
            ->pluck('replay_id');

        if ($replayIds->isEmpty()) {
            return [];
        }

        return Replay::query()
            ->whereIn('id', $replayIds)
            ->playable()
            ->newestFirst()
            ->get()
            ->map(static fn (Replay $replay): array => self::row($replay))
            ->all();
    }

    /**
     * Wer betroffen war, in einer Zeile.
     *
     * Die Reihenfolge ist dieselbe wie beim Zählen der Betroffenen
     * ({@see App\Models\IssueUser::keyFor()}): die ausdrückliche Kennung vor dem
     * Anmeldenamen, der vor der Adresse, die vor der IP. Wer sie umstellte,
     * bekäme in Liste und Fehlerzählung verschiedene Namen für denselben
     * Menschen.
     *
     * @return array{label: string, field: string}|null
     */
    private static function user(Replay $replay): ?array
    {
        $user = $replay->user ?? [];

        foreach (['username', 'email', 'id', 'ip_address'] as $field) {
            $value = $user[$field] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return ['label' => trim($value), 'field' => $field];
            }
        }

        return null;
    }
}
