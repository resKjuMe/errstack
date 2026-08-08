<?php

namespace App\Support\Performance;

/**
 * Ein Wert einer Merkmals-Aufschlüsselung: eine Version, eine Umgebung, eine
 * Plattform — mit ihrer Antwortzeit und der Einordnung.
 */
final class TransactionFacetValue
{
    public function __construct(
        public readonly string $value,
        public readonly int $count,
        public readonly ?int $p95Us,
        public readonly bool $outlier,
    ) {}

    /**
     * @return array{value: string, count: int, p95Us: int|null, outlier: bool}
     */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'count' => $this->count,
            'p95Us' => $this->p95Us,
            'outlier' => $this->outlier,
        ];
    }
}
