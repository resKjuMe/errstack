<?php

namespace App\Support\Discover;

/**
 * Die Grenzen, innerhalb derer eine freie Auswertung läuft.
 *
 * Eine freie Auswertung ist per Bauart eine Abfrage, die niemand vorher gesehen
 * hat: der Fragende bestimmt Quelle, Zeitraum, Gruppierung und Sortierung. Ohne
 * Grenzen ist die erste unbedachte Frage („zähle nach Nutzer-Adresse, letzte 90
 * Tage") eine Abfrage, die die Datenbank für alle anderen blockiert. Die Grenzen
 * sind deshalb kein Misstrauen gegen den Fragenden, sondern die Zusage an alle
 * übrigen Leser.
 *
 * **Geprüft wird, bevor gefragt wird.** Was sich an der Abfrage selbst erkennen
 * lässt — Zeitraum, Zeilenzahl, Zahl der Stützstellen, Zahl der Gruppierungsfelder
 * — kostet keine Datenbank und wird vorab abgelehnt, mit Zahl und Grenze in der
 * Meldung ({@see DiscoverException::limit()}). Nur was sich nicht vorhersehen lässt,
 * bleibt der Datenbank überlassen: die Zeit, die eine Abfrage tatsächlich braucht.
 *
 * **Gekürzt wird nie stillschweigend.** Wo mehr Gruppen vorliegen als die Grenze
 * zulässt, sagt das Ergebnis es ({@see DiscoverResult::$truncated}) — eine
 * abgeschnittene Liste, die aussieht wie eine vollständige, ist die
 * unangenehmste Sorte falscher Antwort.
 */
final class DiscoverLimits
{
    public function __construct(
        public readonly int $maxRows,
        public readonly int $maxGroups,
        public readonly int $maxGroupFields,
        public readonly int $maxAggregations,
        public readonly int $maxPoints,
        public readonly int $maxSeriesGroups,
        public readonly int $maxRangeDays,
        public readonly int $timeoutMs,
        public readonly int $cacheTtl,
        public readonly int $cacheGranularity,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            maxRows: (int) config('discover.max_rows'),
            maxGroups: (int) config('discover.max_groups'),
            maxGroupFields: (int) config('discover.max_group_fields'),
            maxAggregations: (int) config('discover.max_aggregations'),
            maxPoints: (int) config('discover.max_points'),
            maxSeriesGroups: (int) config('discover.max_series_groups'),
            maxRangeDays: (int) config('discover.max_range_days'),
            timeoutMs: (int) config('discover.timeout_ms'),
            cacheTtl: (int) config('discover.cache_ttl'),
            cacheGranularity: (int) config('discover.cache_granularity'),
        );
    }

    /**
     * Prüft eine Abfrage — und wirft beim ersten Verstoß.
     *
     * @throws DiscoverException
     */
    public function check(DiscoverQuery $query): void
    {
        $days = $query->range->seconds() / 86400;

        if ($days > $this->maxRangeDays) {
            throw DiscoverException::limit('range_days', $this->maxRangeDays, round($days, 2));
        }

        if ($query->limit < 1) {
            throw DiscoverException::invalid('Eine Auswertung ohne Zeilen ist keine.');
        }

        if ($query->limit > $this->maxRows) {
            throw DiscoverException::limit('rows', $this->maxRows, $query->limit);
        }

        if (count($query->groupBy) > $this->maxGroupFields) {
            throw DiscoverException::limit('group_fields', $this->maxGroupFields, count($query->groupBy));
        }

        if ($query->aggregations === []) {
            throw DiscoverException::invalid('Eine Auswertung ohne Kennzahl ist keine.');
        }

        if (count($query->aggregations) > $this->maxAggregations) {
            throw DiscoverException::limit('aggregations', $this->maxAggregations, count($query->aggregations));
        }

        if ($query->interval !== null) {
            $points = $query->interval->points($query->range);

            if ($points > $this->maxPoints) {
                throw DiscoverException::limit('points', $this->maxPoints, $points);
            }
        }
    }
}
