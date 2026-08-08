<?php

namespace App\Support\Issues;

/**
 * Was eine Aktion bewirkt hat — und ob sie sich zurücknehmen lässt.
 *
 * Die Anzahl steht getrennt von den Kennungen, weil beide verschiedene Fragen
 * beantworten: `count` sagt, was der Meldung zu entnehmen ist („12.480 Fehler
 * erledigt"), `undoIds` sagt, was ein Klick auf „Rückgängig" anfassen würde.
 * Bei einer Sammelaktion über die ganze Auswahl fallen die beiden auseinander —
 * siehe {@see IssueActions::UNDO_LIMIT}.
 */
final class IssueActionResult
{
    /**
     * @param  list<int>  $undoIds  Leer, wenn kein Rückweg angeboten wird.
     * @param  list<array{project: int, fingerprint: string}>  $discards  Die
     *                                                                    aufgenommenen Verwerfungen — der einzige Teil des Löschens, der
     *                                                                    sich zurücknehmen lässt.
     */
    public function __construct(
        public readonly int $count,
        public readonly array $undoIds = [],
        public readonly array $discards = [],
    ) {}

    public function isUndoable(): bool
    {
        return $this->undoIds !== [] || $this->discards !== [];
    }
}
