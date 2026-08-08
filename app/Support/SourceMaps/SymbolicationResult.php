<?php

namespace App\Support\SourceMaps;

use App\Enums\SymbolicationDiagnosis;
use App\Enums\SymbolicationStatus;

/**
 * Das Ergebnis einer Rückübersetzung: die übersetzten Ausnahmen, die Gründe für
 * das Übrige und die beiden Zahlen dazu.
 *
 * Die Diagnosen werden **zusammengefasst**, nicht aufgezählt. Ein Stacktrace aus
 * vierzig Rahmen desselben Bundles scheitert vierzigmal am selben fehlenden
 * Pfad; vierzig gleichlautende Zeilen wären keine Diagnose, sondern Lärm. Gezählt
 * wird je Grund **und** Einzelheit — „keine Karte für `~/static/js/app.js` (12
 * Rahmen)" ist eine Aussage, über die sich handeln lässt.
 */
final class SymbolicationResult
{
    /**
     * @var array<string, array{reason: string, detail: string|null, count: int}>
     */
    private array $reasons = [];

    /**
     * @param  list<array<string, mixed>>  $exceptions  Die übersetzten Ausnahmen in der Form von `events.exceptions`.
     */
    public function __construct(
        public readonly SymbolicationStatus $status,
        public readonly array $exceptions,
        public readonly int $mappedFrames,
        public readonly int $totalFrames,
    ) {}

    /**
     * Ein Ergebnis mit Gründen — die Zusammenfassung entsteht dabei.
     *
     * @param  list<array<string, mixed>>  $exceptions
     * @param  list<array{0: SymbolicationDiagnosis, 1: string|null}>  $diagnoses
     */
    public static function make(
        SymbolicationStatus $status,
        array $exceptions,
        int $mappedFrames,
        int $totalFrames,
        array $diagnoses,
    ): self {
        $result = new self($status, $exceptions, $mappedFrames, $totalFrames);

        foreach ($diagnoses as [$diagnosis, $detail]) {
            $key = $diagnosis->value.'|'.((string) $detail);

            if (isset($result->reasons[$key])) {
                $result->reasons[$key]['count']++;

                continue;
            }

            $result->reasons[$key] = [
                'reason' => $diagnosis->value,
                'detail' => $detail,
                'count' => 1,
            ];
        }

        return $result;
    }

    /**
     * Die Gründe, häufigster zuerst — das ist die Reihenfolge, in der jemand sie
     * abarbeiten würde.
     *
     * @return list<array{reason: string, detail: string|null, count: int}>
     */
    public function diagnostics(): array
    {
        $reasons = array_values($this->reasons);

        usort($reasons, static fn (array $left, array $right): int => $right['count'] <=> $left['count']);

        return $reasons;
    }
}
