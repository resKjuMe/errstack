<?php

namespace App\Support\Discover;

use Carbon\CarbonImmutable;

/**
 * Die Schrittweite einer Zeitreihe — `5m`, `1h`, `1d`.
 *
 * Eigener Gegenstand und nicht eine Zahl von Sekunden, weil derselbe Wert an drei
 * Stellen gebraucht wird: als Rasterung in SQL, als Zahl der Stützstellen für die
 * Grenzprüfung und als Beschriftung. Wo das dreimal aus einer Zahl gerechnet
 * wird, läuft es auseinander.
 *
 * **Die Stützstellen entstehen hier und nicht aus den Daten.** Eine Reihe, die
 * nur die Stunden enthält, in denen etwas passiert ist, ist keine Reihe: eine
 * Lücke sieht dann aus wie ein Sprung. Deshalb zählt {@see self::buckets()}
 * lückenlos vom Anfang des Zeitraums, und der Motor füllt die Löcher — mit `0`
 * bei einer Anzahl und mit `null` bei allem anderen.
 */
final class Interval
{
    /**
     * Kürzel auf Sekunden. Was hier nicht steht, geht auch nicht — eine
     * Zeitreihe mit 37-Sekunden-Schritten hat niemand gemeint, und eine offene
     * Zahl wäre der Weg zu einer Reihe mit einer Million Punkten.
     *
     * @var array<string, int>
     */
    private const UNITS = [
        '1m' => 60,
        '5m' => 300,
        '15m' => 900,
        '30m' => 1800,
        '1h' => 3600,
        '4h' => 14400,
        '12h' => 43200,
        '1d' => 86400,
        '7d' => 604800,
    ];

    private function __construct(
        public readonly string $key,
        public readonly int $seconds,
    ) {}

    public static function parse(string $key): self
    {
        $key = mb_strtolower(trim($key));
        $seconds = self::UNITS[$key] ?? null;

        if ($seconds === null) {
            throw DiscoverException::invalid('Unbekannte Schrittweite: '.$key);
        }

        return new self($key, $seconds);
    }

    /**
     * @return list<string>
     */
    public static function options(): array
    {
        return array_keys(self::UNITS);
    }

    /**
     * Die feinste Schrittweite, die den Zeitraum in höchstens so viele
     * Stützstellen zerlegt.
     *
     * Die Rechnung steht hier und nicht bei den Aufrufern, weil sie überall
     * dieselbe ist und sich nur im Ziel unterscheidet: die freie Auswertung hat
     * eine ganze Seite für ihr Diagramm, eine Kachel einen Ausschnitt davon.
     * Dreimal dieselbe Schleife nebeneinander liefe irgendwann auseinander —
     * und dann zeigte dieselbe Frage je nach Bildschirm ein anderes Raster.
     *
     * Reicht keine aus (ein Zeitraum, der auch in Wochenschritten zu lang ist),
     * bleibt die gröbste: mehr Punkte sind unschön, ein Fehler wäre hier keine
     * Antwort.
     */
    public static function fitting(TimeRange $range, int $targetPoints): self
    {
        $coarsest = self::parse((string) array_key_last(self::UNITS));

        foreach (self::UNITS as $key => $seconds) {
            $interval = new self($key, $seconds);

            if ($interval->points($range) <= $targetPoints) {
                return $interval;
            }
        }

        return $coarsest;
    }

    /**
     * Wie viele Stützstellen ein Zeitraum bei dieser Schrittweite hat.
     */
    public function points(TimeRange $range): int
    {
        return (int) ceil($range->seconds() / $this->seconds);
    }

    /**
     * Die Anfänge aller Stützstellen, lückenlos.
     *
     * Gerechnet **vom Anfang des Zeitraums** und nicht von der Epoche: sonst
     * begänne die erste Stütze vor dem angefragten Zeitraum, und ihr Balken wäre
     * niedriger als die anderen, ohne dass es dafür einen Grund in den Daten gibt.
     *
     * @return list<CarbonImmutable>
     */
    public function buckets(TimeRange $range): array
    {
        $buckets = [];

        for ($index = 0, $count = $this->points($range); $index < $count; $index++) {
            $buckets[] = $range->from->addSeconds($index * $this->seconds);
        }

        return $buckets;
    }
}
