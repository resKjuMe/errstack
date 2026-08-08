<?php

namespace App\Support\Profiling;

/**
 * Die gewogenen Stichproben eines Ausführungsstrangs — und was dabei liegen
 * blieb.
 *
 * Ein eigener Rückgabewert statt eines Feld-Baums aus fünf Werten: die vier
 * Zahlen daneben sind allesamt Verworfenes, und ein `list` mit vier Zahlen an
 * den Plätzen eins bis vier ist die Art von Rückgabe, bei der beim nächsten
 * Feld jemand die falsche Stelle liest.
 */
final class SampleSet
{
    /**
     * @param  list<array{int, int}>  $paths  Je Stichprobe: Stapel-Platz und Gewicht in Nanosekunden.
     * @param  string|null  $threadId  Der ausgewertete Ausführungsstrang.
     * @param  int  $foreign  Stichproben der übrigen Stränge.
     * @param  int  $unreadable  Stichproben ohne brauchbaren Stapel-Platz oder Zeitpunkt.
     * @param  int  $excess  Stichproben über {@see ProfileEvent::MAX_SAMPLES} hinaus.
     */
    public function __construct(
        public readonly array $paths,
        public readonly ?string $threadId,
        public readonly int $foreign,
        public readonly int $unreadable,
        public readonly int $excess,
    ) {}

    public static function empty(): self
    {
        return new self([], null, 0, 0, 0);
    }
}
