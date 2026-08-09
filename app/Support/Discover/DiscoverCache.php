<?php

namespace App\Support\Discover;

use Illuminate\Support\Facades\Cache;

/**
 * Der Zwischenspeicher der Auswertungen — gleiche Frage, gleiche Antwort.
 *
 * Dieselbe Auswertung wird selten einmal gelesen: eine Seite mit sechs Kacheln (D4)
 * fragt sechsmal, ein Blick auf das Nachbardiagramm noch einmal, und beim Neuladen
 * beginnt es von vorn. Der Schlüssel ist der Fingerabdruck der Abfrage
 * ({@see DiscoverQuery::fingerprint()}) — alles, was das Ergebnis verändert, steckt
 * darin, und nichts, was es nicht verändert.
 *
 * **Zwei Formen, zwei Schlüssel.** Tabelle und Zeitreihe entstehen aus derselben
 * Abfrage und sind verschiedene Antworten; der Fingerabdruck enthält die
 * Schrittweite, aber die Art der Antwort steht zusätzlich im Schlüssel — sonst
 * hinge es an der Reihenfolge der Aufrufe, was zurückkommt.
 *
 * **Was hier nicht passiert: aufräumen.** Die Antworten sind kurzlebig (Sekunden bis
 * eine Minute) und niemals maßgeblich; eine Änderung an den Daten macht sie nicht
 * falsch, sondern alt, und das steht am Ergebnis ({@see DiscoverResult::$cached}).
 * Ein gezieltes Verwerfen wäre der Anfang einer Abhängigkeitsverfolgung über fünf
 * Tabellen — für eine Zahl, die in einer Minute von selbst neu gerechnet wird.
 */
final class DiscoverCache
{
    public function __construct(
        private readonly int $ttl,
    ) {}

    public function table(DiscoverQuery $query): ?DiscoverResult
    {
        $value = $this->read($query, 'table');

        return $value instanceof DiscoverResult ? $value : null;
    }

    public function series(DiscoverQuery $query): ?DiscoverSeries
    {
        $value = $this->read($query, 'series');

        return $value instanceof DiscoverSeries ? $value : null;
    }

    public function store(DiscoverQuery $query, string $kind, DiscoverResult|DiscoverSeries $value): void
    {
        if (! $this->enabled($query)) {
            return;
        }

        Cache::put($this->key($query, $kind), $value, $this->ttl);
    }

    private function read(DiscoverQuery $query, string $kind): mixed
    {
        return $this->enabled($query) ? Cache::get($this->key($query, $kind)) : null;
    }

    private function enabled(DiscoverQuery $query): bool
    {
        return $query->cacheable && $this->ttl > 0;
    }

    private function key(DiscoverQuery $query, string $kind): string
    {
        return 'discover:'.$kind.':'.$query->projectId.':'.$query->fingerprint();
    }
}
