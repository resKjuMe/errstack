<?php

namespace App\Support\Crons;

use App\Enums\CronCheckInStatus;
use Illuminate\Support\Str;

/**
 * Ein Check-in, so wie ein SDK ihn schickt — zerlegt und auf brauchbare Werte
 * zurückgeschnitten.
 *
 * Der Rumpf eines `check_in`-Envelope-Elements sieht so aus:
 *
 *     {
 *       "check_in_id": "e5f6…",
 *       "monitor_slug": "nightly-import",
 *       "status": "ok",
 *       "duration": 42.5,
 *       "environment": "production",
 *       "monitor_config": {
 *         "schedule": {"type": "crontab", "value": "0 2 * * *"},
 *         "checkin_margin": 15,
 *         "max_runtime": 60,
 *         "timezone": "Europe/Berlin",
 *         "failure_issue_threshold": 2,
 *         "recovery_threshold": 1
 *       }
 *     }
 *
 * Alles daran kommt von außen und ist entsprechend zu behandeln: jedes Feld ist
 * einzeln geprüft, jede Zeichenkette gekappt. Ein unbrauchbares Feld macht den
 * Check-in nicht ungültig — es fehlt dann eben. Nur ohne Kennung des Monitors
 * und ohne Zustand lässt sich nichts damit anfangen; dafür steht {@see isValid()}.
 */
final readonly class CheckInPayload
{
    private function __construct(
        public ?string $monitorSlug,
        public ?CronCheckInStatus $status,
        public ?string $checkInId,
        public ?float $durationSeconds,
        public ?string $environment,
        public ?MonitorConfig $config,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            monitorSlug: self::slug($data['monitor_slug'] ?? null),
            status: CronCheckInStatus::fromReported($data['status'] ?? null),
            checkInId: self::checkInId($data['check_in_id'] ?? null),
            durationSeconds: self::duration($data['duration'] ?? null),
            environment: self::text($data['environment'] ?? null, 64),
            config: MonitorConfig::fromArray($data['monitor_config'] ?? null),
        );
    }

    /**
     * Ein Check-in über den einfachen HTTP-Weg. Dort steht die Kennung des
     * Monitors in der Adresse, alles Weitere im Abfrageteil — ein Aufruf mit
     * `curl` soll ohne JSON-Rumpf auskommen.
     *
     * Ohne Angabe gilt `ok`: der Regelfall dieses Wegs ist die eine Zeile am
     * Ende eines Shell-Skripts, und die meint „hat geklappt".
     *
     * @param  array<string, mixed>  $query
     */
    public static function fromRequest(string $monitorSlug, array $query): self
    {
        return new self(
            monitorSlug: self::slug($monitorSlug),
            status: CronCheckInStatus::fromReported($query['status'] ?? null) ?? CronCheckInStatus::Ok,
            checkInId: self::checkInId($query['check_in_id'] ?? null),
            durationSeconds: self::duration($query['duration'] ?? null),
            environment: self::text($query['environment'] ?? null, 64),
            config: null,
        );
    }

    /**
     * Lässt sich mit diesem Check-in überhaupt etwas anfangen?
     */
    public function isValid(): bool
    {
        return $this->monitorSlug !== null && $this->status !== null;
    }

    /**
     * Die Kennung des Monitors, in derselben Form wie beim Anlegen in der
     * Oberfläche. Ein SDK schickt hier, was in seiner Konfiguration steht —
     * inklusive Großschreibung und Leerzeichen.
     */
    private static function slug(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $slug = Str::limit(Str::slug($value), 64, '');

        return $slug === '' ? null : $slug;
    }

    /**
     * Die Kennung eines Laufs: 32 Hex-Zeichen, wie bei einer Meldungsnummer.
     * Alles andere wird verworfen — sie ist der Schlüssel, über den „fertig"
     * seinen „Beginn" wiederfindet, und eine geratene Kennung würde zwei
     * fremde Läufe zusammenwerfen.
     */
    private static function checkInId(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = strtolower(str_replace('-', '', trim($value)));

        return preg_match('/^[0-9a-f]{32}$/', $normalized) === 1 ? $normalized : null;
    }

    /**
     * Die gemeldete Laufzeit in Sekunden. Sentry lässt hier auch Nachkommastellen
     * zu; eine negative Angabe ist keine Dauer.
     */
    private static function duration(mixed $value): ?float
    {
        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
            return null;
        }

        $seconds = (float) $value;

        return $seconds >= 0 ? $seconds : null;
    }

    private static function text(mixed $value, int $limit): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $text = trim($value);

        return $text === '' ? null : mb_substr($text, 0, $limit);
    }
}
