<?php

namespace App\Support\Ingest\Grouping;

use App\Enums\GroupingSource;

/**
 * Die Bestandteile eines Fingerabdrucks samt dem Verfahren, das sie gefunden
 * hat.
 *
 * Getrennt vom fertigen {@see Fingerprint}, weil die Bestandteile eine Stufe
 * vorher gebraucht werden: eine eigene Angabe des SDK oder eine projektweite
 * Regel darf mit `{{ default }}` genau diese Liste in ihren eigenen
 * Fingerabdruck einsetzen. Wäre hier schon der Hash gebildet, ließe sich das
 * nicht mehr zusammensetzen.
 */
final class Components
{
    /**
     * @param  list<Component>  $components
     */
    public function __construct(
        public readonly GroupingSource $source,
        public readonly array $components,
    ) {}

    public function isEmpty(): bool
    {
        return $this->components === [];
    }

    /**
     * Die Bestandteile als Text — die Form, die eine Regel mit `{{ default }}`
     * einsetzt.
     *
     * @return list<string>
     */
    public function values(): array
    {
        return array_map(
            static fn (Component $component): string => $component->signature(),
            $this->components,
        );
    }
}
