<?php

namespace App\Support\Performance\Trace;

use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;

/**
 * Ein Fehler, der in dieser Spur aufgetreten ist.
 *
 * Er hängt an dem Schritt, in dem er gemeldet wurde — genau das ist der Gewinn
 * der Trace-Ansicht gegenüber einer Fehlerliste: nicht „irgendwann in diesem
 * Aufruf", sondern „in dieser Abfrage, nach 3,2 Sekunden".
 */
final class TraceError
{
    private function __construct(
        public readonly int $id,
        public readonly string $eventId,
        public readonly ?string $spanId,
        public readonly string $title,
        public readonly string $level,
        public readonly ?string $culprit,
        public readonly CarbonImmutable $occurredAt,
        public readonly ?string $href,
    ) {}

    public static function fromEvent(Event $event): self
    {
        return new self(
            id: $event->id,
            eventId: $event->event_id,
            spanId: $event->trace_span_id,
            // Ein Eintrag ohne Titel ist kein Datenfehler: eine bloße Meldung
            // ohne Ausnahme hat keinen. Dieselbe Ersatzreihenfolge wie in der
            // Fehlerliste, damit derselbe Fehler nicht an zwei Stellen
            // verschieden heißt.
            title: $event->title ?? $event->culprit ?? __('issues.list.untitled'),
            level: $event->level->value,
            culprit: $event->culprit,
            occurredAt: CarbonImmutable::instance($event->occurred_at),
            href: self::href($event),
        );
    }

    /**
     * Der Weg von hier zum Fehler.
     *
     * Die Fehler-Detailseite entsteht in einer eigenen Aufgabe (S2). Solange es
     * sie nicht gibt, bleibt der Fehler in der Spur trotzdem sichtbar — nur
     * eben ohne Verweis. Ein fest verdrahteter Routenname wäre hier keine
     * Verknüpfung, sondern ein Absturz der ganzen Seite, sobald die Reihenfolge
     * der Aufgaben eine andere ist als geplant.
     */
    private static function href(Event $event): ?string
    {
        $issueId = $event->group?->issue_id;

        if ($issueId === null || ! Route::has('issues.events.show')) {
            return null;
        }

        return route('issues.events.show', ['issue' => $issueId, 'event' => $event->id]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'eventId' => $this->eventId,
            'title' => $this->title,
            'level' => $this->level,
            'culprit' => $this->culprit,
            'occurredAt' => $this->occurredAt->toIso8601String(),
            'href' => $this->href,
        ];
    }
}
