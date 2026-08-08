<?php

namespace App\Support\Releases\Health;

use Carbon\CarbonImmutable;

/**
 * Ein Eintrag eines Sitzungs-Bündels: ein Zeitfenster, ein Nutzer, vier Zahlen.
 *
 * Der Nutzer ist freigestellt. Manche SDKs schlüsseln ihre Bündel nach Nutzer
 * auf, andere fassen alle zusammen — im zweiten Fall stehen die Sitzungen ohne
 * Nutzerangabe da, und die Nutzerzahlen der Version bleiben leer. Das ist
 * kein Fehler, sondern die Grenze dessen, was gemeldet wurde: „wie viele
 * Menschen" ist aus Sitzungssummen nicht zu erraten.
 */
final class SessionBucket
{
    public function __construct(
        public readonly CarbonImmutable $startedAt,
        public readonly ?string $userIdentifier,
        public readonly SessionTally $tally,
    ) {}
}
