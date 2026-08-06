<?php

namespace App\Notifications;

/**
 * Ergebnis eines einzelnen Zustellversuchs, wie der Kanal es zurückmeldet.
 *
 * Ein Kanal darf statt eines Fehlschlags auch eine Ausnahme werfen — der Job
 * behandelt beides gleich. Der Rückgabewert ist der Normalweg, weil er den
 * Antwortcode des Ziels mitbringt und damit im Protokoll landet.
 */
final readonly class DeliveryResult
{
    private function __construct(
        public bool $ok,
        public ?int $responseCode = null,
        public ?string $error = null,
    ) {}

    public static function success(?int $responseCode = null): self
    {
        return new self(true, $responseCode);
    }

    public static function failure(string $error, ?int $responseCode = null): self
    {
        return new self(false, $responseCode, $error);
    }
}
