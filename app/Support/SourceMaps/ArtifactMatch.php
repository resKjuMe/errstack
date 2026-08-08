<?php

namespace App\Support\SourceMaps;

use App\Enums\SymbolicationDiagnosis;
use App\Models\ReleaseArtifact;

/**
 * Das Ergebnis der Suche nach einer Quellkarte: die Karte — oder der Grund,
 * warum es keine gibt.
 *
 * Ein `null` täte es nicht. Die Suche kann an sieben verschiedenen Stellen
 * scheitern, und welche es war, ist die eigentliche Auskunft dieser Aufgabe: „zu
 * dieser Version wurde nichts hochgeladen" verlangt einen Schritt in der
 * Bauumgebung, „zu diesem Pfad gibt es kein Artefakt" einen Blick auf die
 * Adressen, „die Karte ist unlesbar" einen auf den Bauvorgang.
 */
final class ArtifactMatch
{
    private function __construct(
        public readonly ?SourceMap $map,
        public readonly ?ReleaseArtifact $artifact,
        public readonly ?SymbolicationDiagnosis $diagnosis,
        public readonly ?string $detail,
    ) {}

    public static function found(SourceMap $map, ReleaseArtifact $artifact): self
    {
        return new self($map, $artifact, null, null);
    }

    public static function failed(SymbolicationDiagnosis $diagnosis, ?string $detail): self
    {
        return new self(null, null, $diagnosis, $detail);
    }

    public function hasMap(): bool
    {
        return $this->map instanceof SourceMap;
    }
}
