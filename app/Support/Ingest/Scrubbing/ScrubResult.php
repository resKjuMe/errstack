<?php

namespace App\Support\Ingest\Scrubbing;

/**
 * Was das Scrubbing hinterlassen hat: die bereinigte Meldung und die Wege zu
 * allem, was daran geändert wurde.
 *
 * Die Wege sind der Grund, dass es diese Klasse gibt und nicht nur einen
 * Rückgabewert. Sie beantworten zwei Fragen, die beide gestellt werden: in der
 * Vorschau „was würde verschwinden?" und im Protokoll „was ist verschwunden?".
 * Ohne sie bliebe nur der Vergleich zweier Feld-Bäume — und den müsste jeder
 * Aufrufer selbst anstellen.
 */
final class ScrubResult
{
    /**
     * @param  array<mixed>  $data  Die Meldung, wie sie gespeichert werden darf.
     * @param  list<string>  $paths  Wege zu den geänderten Feldern, in der
     *                               Reihenfolge, in der sie vorkamen.
     */
    public function __construct(
        public readonly array $data,
        public readonly array $paths,
    ) {}

    public function changed(): bool
    {
        return $this->paths !== [];
    }
}
