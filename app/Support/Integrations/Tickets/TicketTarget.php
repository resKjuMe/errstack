<?php

namespace App\Support\Integrations\Tickets;

use App\Models\Project;

/**
 * Ein Ort, an dem ein Ticket entstehen kann (X4).
 *
 * Bei Jira ein Projekt, bei Linear ein Team. Zwei Wörter für dieselbe Sache: der
 * Behälter, in dem Tickets liegen und aus dem der Schlüssel vor der Nummer
 * kommt.
 *
 * Die Klasse hat einen dritten Namen (`TicketTarget`) statt sich für einen der
 * beiden zu entscheiden — und das mit Absicht: „Projekt" ist hier schon
 * vergeben ({@see Project}), und ein `TicketProject` neben einem
 * `Project` wäre die Art Namensnachbarschaft, bei der man beim Lesen jedes Mal
 * kurz stehen bleibt.
 */
final readonly class TicketTarget
{
    public function __construct(
        /**
         * Der Schlüssel: `OPS`, `ENG`. Er steht vor der Nummer und ist das, was
         * gespeichert wird.
         */
        public string $key,
        /**
         * Der ausgeschriebene Name („Betrieb", „Engineering") — nur für die
         * Auswahlliste.
         */
        public string $name,
        /**
         * Die Kennung beim Anbieter, wo sie zum Anlegen gebraucht wird: Linear
         * legt über die Team-UUID an, nicht über den Schlüssel.
         */
        public ?string $externalId = null,
    ) {}

    /**
     * @return array{key: string, name: string}
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'name' => $this->name];
    }
}
