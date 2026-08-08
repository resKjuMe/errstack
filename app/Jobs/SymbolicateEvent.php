<?php

namespace App\Jobs;

use App\Enums\QueueName;
use App\Enums\SymbolicationStatus;
use App\Models\Event;
use App\Models\EventSymbolication;
use App\Support\Ingest\Processing\Steps\QueueSymbolication;
use App\Support\SourceMaps\ArtifactStore;
use App\Support\SourceMaps\Symbolicator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Übersetzt den Stacktrace einer Meldung im Hintergrund zurück.
 *
 * **Im Hintergrund, weil das Einlesen einer Quellkarte teuer ist**: eine Karte
 * mit eingebettetem Quelltext bringt zweistellige Megabyte mit. Das in der
 * Aufnahme zu tun hieße, die Antwortzeit der überwachten Anwendung an unsere
 * Ablage zu hängen; es beim Aufschlagen der Fehlerseite zu tun hieße, sie
 * jedesmal zu bezahlen.
 *
 * Der Auftrag wird an zwei Stellen eingereiht, und das ist Absicht: am Ende der
 * Aufnahme ({@see QueueSymbolication}) und
 * beim Aufschlagen der Fehlerseite, falls noch keine Übersetzung vorliegt. Die
 * zweite Stelle ist die wichtigere — Quellkarten kommen in der Praxis **nach** den
 * ersten Fehlern.
 *
 * Er ist wiederholbar, ohne Schaden anzurichten: die Vormerkung entscheidet, wer
 * rechnet ({@see EventSymbolication::reserve()}), und ein zweiter Anlauf findet
 * ein Ergebnis vor.
 */
class SymbolicateEvent implements ShouldQueue
{
    use Queueable;

    /**
     * Drei Versuche. Wiederholung hilft hier gegen genau eine Sache — eine
     * kurzzeitig nicht erreichbare Ablage; gegen eine unlesbare Quellkarte hilft
     * sie nicht, und die ist ohnehin kein Fehlschlag, sondern ein Ergebnis.
     */
    public int $tries = 3;

    /**
     * Zwei Minuten. Eine Karte einzulesen und zu zerlegen dauert bei einem
     * großen Bundle Sekunden, nicht Minuten — was länger braucht, hängt.
     */
    public int $timeout = 120;

    /**
     * Ist die Meldung aufgeräumt, ist der Auftrag gegenstandslos und kein
     * Fehlschlag.
     */
    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public Event $event,
    ) {
        $this->onQueue(QueueName::Symbolication->value);
    }

    /**
     * Zwei Arbeiter dürfen nicht dieselbe Karte für dieselbe Meldung einlesen.
     *
     * Die Vormerkung allein verhindert das nicht ganz: sie entscheidet, wer
     * rechnet, aber der Zweite käme unmittelbar danach an und fände eine Zeile
     * im Zustand „läuft", die er nicht von einer abgebrochenen unterscheiden
     * kann. Zurückgestellt statt verworfen, damit ein weggebrochener Erster die
     * Meldung nicht dauerhaft unübersetzt lässt.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('symbolicate-event:'.$this->event->id))
                ->releaseAfter(15)
                ->expireAfter($this->timeout + 60),
        ];
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(Symbolicator $symbolicator): void
    {
        [$record, $reserved] = EventSymbolication::reserve($this->event);

        if (! $reserved && $record->status !== SymbolicationStatus::Pending) {
            // Schon gerechnet. Der Regelfall bei einer zweiten Zustellung —
            // und der Zwischenspeicher, für den die Tabelle da ist.
            return;
        }

        $startedAt = hrtime(true);

        $result = $symbolicator->symbolicate($this->event);

        $record->complete($result, (int) round((hrtime(true) - $startedAt) / 1_000_000));
    }

    /**
     * Nach dem letzten vergeblichen Versuch.
     *
     * Die Vormerkung wird auf „gescheitert" gesetzt und nicht gelöscht. Der
     * Unterschied zeigt sich in der Anzeige: eine gelöschte Zeile führt beim
     * nächsten Aufschlagen zu einem neuen Auftrag, der aus demselben Grund
     * scheitert — eine gescheiterte Zeile sagt, dass es versucht wurde. Wer es
     * erneut versuchen will, lädt Artefakte hoch; das räumt sie weg
     * ({@see ArtifactStore::invalidateSymbolications()}).
     */
    public function failed(?Throwable $exception): void
    {
        $record = EventSymbolication::query()->firstWhere('event_id', $this->event->id);

        if ($record === null || $record->status->hasFrames()) {
            return;
        }

        $record->forceFill(['status' => SymbolicationStatus::Failed])->save();

        Log::error('Rückübersetzung eines Stacktraces gescheitert.', [
            'ereignis' => $this->event->id,
            'projekt' => $this->event->project_id,
            'grund' => $exception?->getMessage(),
        ]);
    }
}
