<?php

namespace App\Support\Ingest\Processing;

use App\Enums\DiscardReason;
use App\Models\IngestPayload;

/**
 * Was ein Verarbeitungsschritt vorfindet und was er weitergibt.
 *
 * Die Kette reicht diesen einen Gegenstand durch, statt jeden Schritt eine
 * eigene Form zurückgeben zu lassen. Der Grund ist die Reihenfolge: Filter,
 * Scrubbing, Normalisierung, Grouping und Aggregation stehen hintereinander,
 * aber nicht jeder braucht das Ergebnis seines Vorgängers. Müsste jeder Schritt
 * die Form des nächsten kennen, wäre keiner mehr für sich einzusetzen — und
 * genau das ist die Anforderung: die Kette soll wachsen, ohne dass jemand die
 * bestehenden Schritte anfasst.
 *
 * Ablegen und Abholen läuft deshalb über Namen ({@see with()}, {@see get()}).
 * Ein Schritt, der etwas nicht findet, weiß dann selbst am besten, ob er ohne
 * arbeiten kann.
 */
final class ProcessingContext
{
    /**
     * Der Rumpf als Feld-Baum — bei Anhängen und Aufzeichnungen `null`, weil
     * dort kein JSON steht.
     *
     * @var array<mixed>|null
     */
    public ?array $data = null;

    /**
     * Ergebnisse der Schritte, unter frei gewählten Namen.
     *
     * @var array<string, mixed>
     */
    private array $results = [];

    private ?DiscardReason $dropReason = null;

    private ?string $dropDetail = null;

    public function __construct(
        public readonly IngestPayload $payload,
    ) {}

    /**
     * Sortiert die Meldung aus: die Kette bricht ab, die Rohdaten bleiben
     * liegen.
     *
     * Das ist kein Fehler, sondern eine Entscheidung — unlesbare Nutzdaten, ein
     * Eingangsfilter (I8), eine Stichprobe, in die die Meldung nicht fällt
     * (I9). Deshalb wird auch nichts wiederholt: ein zweiter Durchlauf käme zum
     * selben Schluss.
     *
     * @param  string|null  $detail  Was genau ausgesondert hat, für die Zählung
     *                               je Filterart und die Protokollzeile.
     */
    public function drop(DiscardReason $reason, ?string $detail = null): void
    {
        $this->dropReason = $reason;
        $this->dropDetail = $detail;
    }

    public function isDropped(): bool
    {
        return $this->dropReason !== null;
    }

    public function dropReason(): ?DiscardReason
    {
        return $this->dropReason;
    }

    public function dropDetail(): ?string
    {
        return $this->dropDetail;
    }

    /**
     * Legt das Ergebnis eines Schritts für die folgenden ab.
     */
    public function with(string $key, mixed $value): void
    {
        $this->results[$key] = $value;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->results[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->results);
    }
}
