<?php

namespace App\Support\Releases\Health;

use App\Models\Release;

/**
 * Die Meldung über **mehrere** Sitzungen, aus einem `sessions`-Element gelesen.
 *
 * So schicken Server-SDKs ihre Sitzungen: nicht einzeln, sondern als fertige
 * Zahlen je Minute und Nutzer. Der Grund ist derselbe, aus dem es diese
 * Auswertung überhaupt gibt — ein Webserver hat pro Sekunde mehr Anfragen als
 * eine App am Tag Starts, und jede davon als eigenes Element zu schicken wäre
 * teurer als das, was gemessen werden soll.
 *
 * ```
 * {"aggregates":[{"started":"2026-08-11T10:03:00Z","did":"u-7",
 *                 "exited":41,"errored":2,"crashed":1,"abnormal":0}],
 *  "attrs":{"release":"1.4.2","environment":"production"}}
 * ```
 *
 * **Hier gibt es nichts wiederzufinden und nichts zu verrechnen.** Ein Bündel
 * enthält keine Sitzungsnummern, also auch keine Zwischenstände: wer bündelt,
 * hat sie schon selbst verrechnet. Die Zahlen werden deshalb geradeheraus
 * addiert — die Abwehr gegen Doppelzählung greift eine Ebene höher, beim
 * Erkennen doppelt zugestellter Meldungen.
 */
final class SessionBatch
{
    /**
     * @param  list<SessionBucket>  $buckets
     */
    private function __construct(
        public readonly string $version,
        public readonly ?string $environment,
        public readonly array $buckets,
    ) {}

    /**
     * Liest ein `sessions`-Element. `null`, wenn nichts Zählbares darin steht.
     *
     * @param  array<mixed>|null  $data
     */
    public static function fromPayload(?array $data): ?self
    {
        if ($data === null) {
            return null;
        }

        $attrs = is_array($data['attrs'] ?? null) ? $data['attrs'] : [];
        $version = Release::normalizeVersion(is_string($attrs['release'] ?? null) ? $attrs['release'] : null);
        $aggregates = $data['aggregates'] ?? null;

        if ($version === null || ! is_array($aggregates)) {
            return null;
        }

        $buckets = [];

        foreach ($aggregates as $aggregate) {
            $bucket = self::bucket($aggregate);

            if ($bucket !== null) {
                // Ein unlesbarer Eintrag nimmt die anderen nicht mit: das
                // Bündel ist eine Zusammenfassung vieler Minuten, und eine
                // kaputte darf nicht eine Stunde Betrieb kosten.
                $buckets[] = $bucket;
            }
        }

        return $buckets === [] ? null : new self($version, is_string($attrs['environment'] ?? null) ? $attrs['environment'] : null, $buckets);
    }

    /**
     * Ein Eintrag des Bündels: Zeitfenster, Nutzer und die vier Zahlen.
     */
    private static function bucket(mixed $aggregate): ?SessionBucket
    {
        if (! is_array($aggregate)) {
            return null;
        }

        $started = SessionValues::time($aggregate['started'] ?? null);

        if ($started === null) {
            return null;
        }

        $exited = SessionValues::count($aggregate['exited'] ?? null);
        $errored = SessionValues::count($aggregate['errored'] ?? null);
        $crashed = SessionValues::count($aggregate['crashed'] ?? null);
        $abnormal = SessionValues::count($aggregate['abnormal'] ?? null);

        $tally = new SessionTally($exited + $errored + $crashed + $abnormal, $errored, $crashed, $abnormal);

        if ($tally->isEmpty()) {
            // Ein Eintrag über null Sitzungen ist keine Aussage. Er kommt vor,
            // wenn ein SDK sein Raster mitschickt, auch wo nichts passiert ist.
            return null;
        }

        return new SessionBucket($started, SessionValues::identifier($aggregate['did'] ?? null), $tally);
    }
}
