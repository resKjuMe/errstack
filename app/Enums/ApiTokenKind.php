<?php

namespace App\Enums;

/**
 * Art eines API-Tokens. Technisch steckt der Unterschied in `tokenable`: ein
 * persönliches Token hängt am Nutzer, ein organisationsweites an der
 * Organisation selbst.
 *
 * Praktisch ist der Unterschied, was beim Ausscheiden passiert: ein persönliches
 * Token verliert mit der Mitgliedschaft seine Rechte, ein organisationsweites
 * gilt weiter — es gehört der Organisation und nicht der Person, die es angelegt
 * hat. Für Server, CI-Läufe und Skripte ist deshalb das organisationsweite die
 * richtige Wahl.
 */
enum ApiTokenKind: string
{
    case Personal = 'personal';
    case Organization = 'organization';

    public function label(): string
    {
        return match ($this) {
            self::Personal => 'Persönlich',
            self::Organization => 'Organisationsweit',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Personal => 'Handelt in deinem Namen und endet mit deiner Mitgliedschaft.',
            self::Organization => 'Gehört der Organisation und gilt unabhängig von einzelnen Konten.',
        };
    }
}
