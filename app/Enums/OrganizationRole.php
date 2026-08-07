<?php

namespace App\Enums;

/**
 * Rolle einer Mitgliedschaft in einer Organisation. Die Rollen bilden eine
 * Rangfolge: wer eine Rolle hat, darf alles, was die darunterliegenden dürfen.
 * Durchgesetzt wird das ausschließlich in den Policies (App\Policies) — die
 * Controller fragen nie selbst nach der Rolle.
 */
enum OrganizationRole: string
{
    /** Vollzugriff samt Löschen der Organisation und Vergabe der Besitzer-Rolle. */
    case Owner = 'owner';

    /** Verwaltet Stammdaten, Mitglieder, Einladungen und Teams. */
    case Admin = 'admin';

    /** Arbeitet mit den Daten der Organisation. */
    case Member = 'member';

    /** Sieht die Daten der Organisation, ändert aber nichts. */
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Besitzer',
            self::Admin => 'Verwaltung',
            self::Member => 'Mitglied',
            self::Viewer => 'Lesend',
        };
    }

    /**
     * Rang innerhalb der Rangfolge — je höher, desto mehr Rechte.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Owner => 40,
            self::Admin => 30,
            self::Member => 20,
            self::Viewer => 10,
        };
    }

    public function atLeast(self $role): bool
    {
        return $this->rank() >= $role->rank();
    }
}
