<?php

namespace App\Support\Performance\Vitals;

use App\Enums\VitalRating;

/**
 * Ein Wert einer Aufschlüsselung: wie oft, wie schnell, wie bewertet.
 *
 * Eine eigene Klasse und kein Feld, damit die Anzeige mit benannten Angaben
 * arbeitet statt mit Feldschlüsseln, die niemand prüft.
 */
final class VitalFacetValue
{
    public function __construct(
        public readonly string $value,
        public readonly int $count,
        public readonly int $percentileValue,
        public readonly VitalRating $rating,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'count' => $this->count,
            'measuredValue' => $this->percentileValue,
            'rating' => $this->rating->value,
            'ratingLabel' => $this->rating->label(),
        ];
    }
}
