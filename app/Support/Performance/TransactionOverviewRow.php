<?php

namespace App\Support\Performance;

use App\Enums\TransactionSort;
use App\Models\Transaction;
use App\Models\TransactionAggregate;
use App\Models\TransactionUserAggregate;

/**
 * Eine Zeile der Performance-Übersicht: eine Transaktion (Name und Operation)
 * mit allen Kennzahlen des gewählten Zeitraums.
 *
 * Die abgeleiteten Werte entstehen im Konstruktor und nicht bei jedem Zugriff.
 * Der Grund ist das Sortieren: es fragt jede Kennzahl mehrfach ab, und ein
 * Perzentil, das dabei jedes Mal neu aus der Verteilung gelesen würde, wäre
 * Arbeit ohne Ertrag.
 *
 * Was hier **nicht** passiert: rechnen mit Einzelmessungen. Alles in dieser
 * Zeile kommt aus den Vorberechnungen ({@see TransactionAggregate},
 * {@see TransactionUserAggregate}) — deshalb kostet eine Zeile gleich viel, ob
 * hinter ihr zehn Aufrufe stehen oder zehn Millionen.
 */
final class TransactionOverviewRow
{
    /**
     * Aufrufe je Minute, **hochgerechnet** auf die tatsächliche Zahl (I9). Nicht
     * die Zahl der gespeicherten Messungen: bei 10 % Stichprobe wäre der
     * ausgewiesene Verkehr sonst ein Zehntel des echten — und zwar unauffällig
     * falsch, denn an den Messungen selbst fehlt nichts.
     */
    public readonly float $throughputPerMinute;

    /**
     * Der Anteil nicht erfolgreicher Aufrufe zwischen 0 und 1 — aus den
     * **gemessenen** Zahlen und ausdrücklich nicht hochgerechnet. Ein Anteil
     * lässt sich aus einer Stichprobe unverzerrt schätzen; ihn zusätzlich mit
     * dem Gewicht zu multiplizieren, würde Zähler und Nenner gleichermaßen
     * strecken und nichts ändern außer der Genauigkeit
     * ({@see Transaction::sampleWeight()}).
     */
    public readonly ?float $failureRate;

    public readonly ?float $averageUs;

    public readonly ?int $p50Us;

    public readonly ?int $p75Us;

    public readonly ?int $p95Us;

    public readonly ?int $p99Us;

    /**
     * Der Anteil der Nutzer, denen diese Transaktion zu langsam war. `null` ohne
     * bekannte Nutzer — und das ist etwas anderes als null Prozent: eine
     * Hintergrundaufgabe ohne Nutzerkennung hat keine zufriedenen Nutzer, sie
     * hat gar keine.
     */
    public readonly ?float $userMisery;

    /**
     * @param  float  $extrapolatedCount  Hochgerechnete Zahl der Aufrufe
     * @param  int  $transactionCount  Tatsächlich gespeicherte Messungen — die
     *                                 Grundlage der Verteilung und damit die
     *                                 Antwort auf „wie sicher ist dieses p95?"
     * @param  int  $minutes  Länge des Zeitraums in Minuten, für den Durchsatz
     */
    public function __construct(
        public readonly string $name,
        public readonly string $op,
        public readonly int $transactionCount,
        public readonly float $extrapolatedCount,
        public readonly int $failureCount,
        public readonly int $durationSumUs,
        public readonly ?int $minUs,
        public readonly ?int $maxUs,
        DurationHistogram $histogram,
        public readonly int $users,
        public readonly int $miserableUsers,
        public readonly TransactionTrend $trend,
        int $minutes,
    ) {
        $this->throughputPerMinute = $extrapolatedCount / max(1, $minutes);

        $this->failureRate = $transactionCount === 0
            ? null
            : $failureCount / $transactionCount;

        $this->averageUs = $transactionCount === 0
            ? null
            : $durationSumUs / $transactionCount;

        $this->p50Us = $histogram->percentile(0.5);
        $this->p75Us = $histogram->percentile(0.75);
        $this->p95Us = $histogram->percentile(0.95);
        $this->p99Us = $histogram->percentile(0.99);

        $this->userMisery = $users === 0 ? null : $miserableUsers / $users;
    }

    /**
     * Der Wert, nach dem eine Spalte sortiert.
     */
    public function metric(TransactionSort $sort): int|float|string|null
    {
        return match ($sort) {
            TransactionSort::Name => $this->name,
            TransactionSort::Throughput => $this->throughputPerMinute,
            TransactionSort::P50 => $this->p50Us,
            TransactionSort::P75 => $this->p75Us,
            TransactionSort::P95 => $this->p95Us,
            TransactionSort::P99 => $this->p99Us,
            TransactionSort::Average => $this->averageUs,
            TransactionSort::FailureRate => $this->failureRate,
            TransactionSort::Users => $this->users,
            TransactionSort::UserMisery => $this->userMisery,
            TransactionSort::Count => $this->transactionCount,
            // Der Trend sortiert nach der Größe der Änderung. „Neu" und „zu
            // wenig Daten" haben keine und landen damit am Ende — dort, wo auch
            // jede andere fehlende Kennzahl steht.
            TransactionSort::Trend => $this->trend->changeRatio,
        };
    }

    /**
     * Bringt die Zeilen in die gewünschte Reihenfolge.
     *
     * Sortiert wird **in PHP** und nicht in der Datenbank, und das ist kein
     * Zugeständnis: die Perzentile entstehen erst hier, aus den zusammengelegten
     * Verteilungen. Bezahlbar ist es, weil die Zahl der Zeilen die der
     * Transaktionsnamen ist und nicht die der Messungen — ein paar hundert
     * gegenüber Millionen.
     *
     * @param  list<self>  $rows
     * @return list<self>
     */
    public static function sorted(array $rows, TransactionSort $sort, bool $descending): array
    {
        usort($rows, function (self $left, self $right) use ($sort, $descending): int {
            $comparison = self::compare($left->metric($sort), $right->metric($sort), $descending);

            // Bei Gleichstand immer dieselbe Reihenfolge, unabhängig von der
            // Sortierrichtung. Ohne diesen zweiten Schlüssel könnte dieselbe
            // Anfrage zwei verschiedene Seiten liefern — und beim Blättern
            // stünde eine Transaktion zweimal da, während eine andere fehlt.
            return $comparison !== 0
                ? $comparison
                : [$left->name, $left->op] <=> [$right->name, $right->op];
        });

        return $rows;
    }

    /**
     * Vergleicht zwei Kennzahlen.
     *
     * `null` heißt „nicht zu berechnen" und sortiert in **beiden** Richtungen ans
     * Ende. Als kleinster Wert behandelt stünde eine Transaktion ohne Nutzer bei
     * aufsteigender Sortierung ganz oben — und die Liste begänne mit dem, worüber
     * es nichts zu sagen gibt.
     */
    private static function compare(int|float|string|null $left, int|float|string|null $right, bool $descending): int
    {
        if ($left === null || $right === null) {
            return $left === $right ? 0 : ($left === null ? 1 : -1);
        }

        $comparison = is_string($left) || is_string($right)
            ? strcasecmp((string) $left, (string) $right)
            : $left <=> $right;

        return $descending ? -$comparison : $comparison;
    }

    /**
     * Die Zeile, wie die Oberfläche sie bekommt.
     *
     * Dauern bleiben in Mikrosekunden: die Umrechnung in eine lesbare Einheit ist
     * eine Anzeigefrage, und wer sie hier erledigte, müsste sich zwischen „ms"
     * und „s" entscheiden, bevor der Wert bekannt ist.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'op' => $this->op,
            'count' => $this->transactionCount,
            // Gerundet, weil die hochgerechnete Anzahl aus Kehrwerten entsteht
            // und sonst mit fünfzehn Nachkommastellen in der Nutzlast steht.
            'extrapolatedCount' => round($this->extrapolatedCount, 2),
            'throughput' => round($this->throughputPerMinute, 4),
            'failures' => $this->failureCount,
            'failureRate' => $this->failureRate,
            'avgUs' => $this->averageUs === null ? null : (int) round($this->averageUs),
            'minUs' => $this->minUs,
            'maxUs' => $this->maxUs,
            'p50Us' => $this->p50Us,
            'p75Us' => $this->p75Us,
            'p95Us' => $this->p95Us,
            'p99Us' => $this->p99Us,
            'users' => $this->users,
            'miserableUsers' => $this->miserableUsers,
            'userMisery' => $this->userMisery,
            'trend' => $this->trend->direction->value,
            'trendLabel' => $this->trend->direction->label(),
            'changeRatio' => $this->trend->changeRatio,
        ];
    }
}
