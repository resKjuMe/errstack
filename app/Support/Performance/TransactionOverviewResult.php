<?php

namespace App\Support\Performance;

/**
 * Was eine Anfrage an die Performance-Übersicht zurückgibt: die Zeilen der
 * angeforderten Seite, und das, was die Oberfläche braucht, um sie einzuordnen.
 */
final class TransactionOverviewResult
{
    /**
     * @param  list<TransactionOverviewRow>  $rows  die Zeilen **dieser** Seite
     * @param  int  $total  alle gefundenen Transaktionen, nicht nur die
     *                      angezeigten
     * @param  bool  $truncated  ob die Obergrenze an Gruppen gegriffen hat und
     *                           die Liste damit unvollständig ist
     */
    public function __construct(
        public readonly array $rows,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
        public readonly bool $truncated,
    ) {}

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / max(1, $this->perPage)));
    }

    /**
     * Die Angaben zum Blättern. Bewusst nur Zahlen und keine fertigen Links: die
     * Adresse der nächsten Seite muss den Filter, die Suche und die Sortierung
     * mittragen, und die kennt die Oberfläche ohnehin — sie baut sie aus
     * derselben Adresszeile, in der sie stehen.
     *
     * @return array{page: int, perPage: int, total: int, lastPage: int}
     */
    public function pagination(): array
    {
        return [
            'page' => $this->page,
            'perPage' => $this->perPage,
            'total' => $this->total,
            'lastPage' => $this->lastPage(),
        ];
    }
}
