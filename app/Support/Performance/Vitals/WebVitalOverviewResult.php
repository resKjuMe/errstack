<?php

namespace App\Support\Performance\Vitals;

/**
 * Was eine Anfrage an die Web-Vitals-Übersicht zurückgibt: die Zeilen der
 * angeforderten Seite und das, was die Oberfläche braucht, um sie einzuordnen.
 */
final class WebVitalOverviewResult
{
    /**
     * @param  list<WebVitalPageRow>  $rows  die Zeilen **dieser** Seite
     * @param  int  $total  alle gefundenen Seiten, nicht nur die angezeigten
     * @param  bool  $truncated  ob die Obergrenze an Seiten gegriffen hat und
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
     * Die Angaben zum Blättern. Bewusst nur Zahlen und keine fertigen Links —
     * dieselbe Überlegung wie bei der Performance-Übersicht: die Adresse der
     * nächsten Seite muss Filter und Suche mittragen, und die kennt die
     * Oberfläche aus derselben Adresszeile, in der sie stehen.
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
