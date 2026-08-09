<?php

namespace App\Support\Releases\Health;

use App\Enums\SessionStatus;
use App\Models\Release;
use App\Models\ReleaseSession;
use Carbon\CarbonImmutable;

/**
 * Die Meldung über **eine** Sitzung, aus einem `session`-Element gelesen.
 *
 * Ein eigener Gegenstand und kein Feld-Baum, weil danach niemand mehr in fremde
 * Schlüsselnamen greifen muss: was das SDK schickt, wird hier einmal gedeutet,
 * und alles Weitere arbeitet mit geprüften Werten. Was sich nicht deuten lässt,
 * ergibt `null` — die Sitzung wird dann verworfen und gezählt, statt mit
 * Ersatzwerten in die Statistik zu geraten.
 *
 * Gelesen wird nur, was für die Gesundheit einer Version zählt. Die Angaben zu
 * Gerät und Herkunft (`ip_address`, `user_agent`) bleiben liegen: sie sind
 * personenbezogen, und für „wie viele Sitzungen sind abgestürzt" braucht sie
 * niemand.
 */
final class SessionUpdate
{
    private function __construct(
        public readonly string $sid,
        public readonly ?string $userIdentifier,
        public readonly SessionStatus $status,
        public readonly int $errors,
        public readonly int $seq,
        public readonly CarbonImmutable $startedAt,
        public readonly CarbonImmutable $seenAt,
        public readonly string $version,
        public readonly ?string $environment,
    ) {}

    /**
     * Liest ein `session`-Element.
     *
     * @param  array<mixed>|null  $data
     */
    public static function fromPayload(?array $data): ?self
    {
        if ($data === null) {
            return null;
        }

        $sid = ReleaseSession::normalizeSid($data['sid'] ?? null);
        $attrs = is_array($data['attrs'] ?? null) ? $data['attrs'] : [];
        $version = Release::normalizeVersion(is_string($attrs['release'] ?? null) ? $attrs['release'] : null);

        if ($sid === null || $version === null) {
            // Ohne Sitzungsnummer ließe sich eine Folgemeldung nicht zuordnen —
            // jede Zwischenmeldung wäre eine neue Sitzung. Ohne Version gehört
            // die Sitzung zu keiner Auslieferung, und genau darum geht es hier.
            return null;
        }

        $started = SessionValues::time($data['started'] ?? null);

        if ($started === null) {
            return null;
        }

        $seen = SessionValues::time($data['timestamp'] ?? null) ?? $started;

        return new self(
            $sid,
            SessionValues::identifier($data['did'] ?? null),
            SessionStatus::fromPayload($data['status'] ?? null),
            SessionValues::count($data['errors'] ?? null),
            self::sequence($data, $seen),
            $started,
            $seen,
            $version,
            is_string($attrs['environment'] ?? null) ? $attrs['environment'] : null,
        );
    }

    /**
     * Die Strichliste dieser einen Sitzung.
     */
    public function tally(): SessionTally
    {
        return $this->status->tally($this->errors);
    }

    /**
     * Die Folgenummer, an der sich eine überholte Meldung erkennen lässt.
     *
     * Das SDK zählt sie je Sitzung hoch. Fehlt sie, tritt der Zeitpunkt an
     * ihre Stelle — schlechter, aber besser als „alles gleich alt": ohne jede
     * Ordnung machte eine verspätete „läuft"-Meldung einen bereits gezählten
     * Absturz wieder rückgängig. Die erste Meldung einer Sitzung (`init`) ist
     * ausdrücklich die nullte; sie darf nichts überholen.
     *
     * @param  array<mixed>  $data
     */
    private static function sequence(array $data, CarbonImmutable $seen): int
    {
        $seq = $data['seq'] ?? null;

        if (is_int($seq) || (is_string($seq) && preg_match('/^\d+$/', $seq) === 1)) {
            return (int) $seq;
        }

        return ($data['init'] ?? false) === true ? 0 : $seen->getTimestamp();
    }
}
