<?php

namespace App\Support\Performance;

use App\Models\Transaction;
use App\Models\TransactionSpan;
use Carbon\CarbonImmutable;

/**
 * Ein gemeldeter Einzelschritt, geprüft und in der Form, in der er abgelegt
 * wird.
 *
 * Zwischen dem Feld-Baum des SDK und der Zeile in der Datenbank liegt genau
 * diese Klasse: sie entscheidet, ob ein Schritt brauchbar ist, und sie hält das
 * Ergebnis fest. Das ist der Grund, sie überhaupt zu haben — ohne sie müsste die
 * Ablage beim Einfügen prüfen, und ein unbrauchbarer Schritt fiele erst dann
 * auf, wenn die Einfügung schon läuft.
 */
final class SpanInput
{
    /**
     * @param  array<string, mixed>|null  $data
     */
    private function __construct(
        public readonly string $spanId,
        public readonly ?string $parentSpanId,
        public readonly ?string $op,
        public readonly ?string $description,
        public readonly ?string $status,
        public readonly CarbonImmutable $startedAt,
        public readonly CarbonImmutable $finishedAt,
        public readonly int $durationUs,
        public readonly ?array $data,
    ) {}

    /**
     * Liest einen Schritt aus dem gemeldeten Feld-Baum.
     *
     * `null` heißt: nicht ablegbar. Ohne eigene Kennung wäre der Schritt im Baum
     * nicht zu verorten, ohne Anfang und Ende hat er keine Dauer — und ein
     * Schritt ohne Dauer ist in einer Antwortzeit-Analyse nichts.
     *
     * @param  array<mixed>  $raw
     */
    public static function fromArray(array $raw): ?self
    {
        $spanId = PayloadReader::hex($raw['span_id'] ?? null, 16);
        $startedAt = PayloadReader::time($raw['start_timestamp'] ?? null);
        $finishedAt = PayloadReader::time($raw['timestamp'] ?? null);

        if ($spanId === null || $startedAt === null || $finishedAt === null) {
            return null;
        }

        return new self(
            spanId: $spanId,
            parentSpanId: PayloadReader::hex($raw['parent_span_id'] ?? null, 16),
            op: PayloadReader::text($raw['op'] ?? null, Transaction::OP_LIMIT),
            description: PayloadReader::text($raw['description'] ?? null, TransactionSpan::DESCRIPTION_LIMIT),
            status: PayloadReader::text($raw['status'] ?? null, 32),
            startedAt: $startedAt,
            finishedAt: $finishedAt,
            durationUs: Duration::between($startedAt, $finishedAt),
            data: PayloadReader::map($raw['data'] ?? null),
        );
    }
}
