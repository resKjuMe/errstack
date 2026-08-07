<?php

namespace App\Support\Crons;

use App\Enums\CronIntervalUnit;
use App\Enums\CronScheduleType;
use Cron\CronExpression;
use DateTimeZone;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Der Zeitplan eines überwachten Jobs — als eigener Wert, unabhängig davon, ob
 * er aus der Oberfläche, aus der Datenbank oder aus einem Check-in stammt.
 *
 * Er kann genau eine Frage beantworten: **wann ist die nächste Ausführung nach
 * diesem Zeitpunkt fällig?** Alles Weitere — Toleranz, Laufzeit, Alarm — baut
 * darauf auf, gehört aber nicht hierher.
 *
 * Die Zeitzone ist Teil des Zeitplans, nicht ein Anzeigedetail. „Täglich 02:00"
 * ohne Zeitzone ist keine Angabe, und die Zeitzone des Servers ist die falsche
 * Antwort darauf: der Job läuft dort, wo er läuft. Gerechnet wird deshalb in
 * der Zeitzone des Monitors und erst danach nach UTC zurückgegeben — so
 * verschiebt sich ein nächtlicher Lauf zur Zeitumstellung eben nicht.
 */
final readonly class CronSchedule
{
    private function __construct(
        public CronScheduleType $type,
        public ?string $expression,
        public ?int $intervalValue,
        public ?CronIntervalUnit $intervalUnit,
        public string $timezone,
    ) {}

    /**
     * Zeitplan aus einem Cron-Ausdruck.
     *
     * @throws InvalidArgumentException bei einem Ausdruck, den niemand lesen kann
     */
    public static function crontab(string $expression, string $timezone = 'UTC'): self
    {
        $expression = trim($expression);

        if (! self::isValidExpression($expression)) {
            throw new InvalidArgumentException("Kein gültiger Cron-Ausdruck: „{$expression}\".");
        }

        return new self(CronScheduleType::Crontab, $expression, null, null, self::normalizeTimezone($timezone));
    }

    /**
     * Zeitplan aus einem Abstand.
     */
    public static function interval(int $value, CronIntervalUnit $unit, string $timezone = 'UTC'): self
    {
        if ($value < 1) {
            throw new InvalidArgumentException('Ein Intervall braucht einen Wert von mindestens 1.');
        }

        return new self(CronScheduleType::Interval, null, $value, $unit, self::normalizeTimezone($timezone));
    }

    /**
     * Der nächste fällige Zeitpunkt **nach** `$after` (nie der Zeitpunkt
     * selbst), als UTC.
     *
     * Der Unterschied zwischen den beiden Formen steckt genau hier: ein
     * Cron-Ausdruck liegt fest im Kalender und richtet sich nicht danach, wann
     * zuletzt etwas lief; ein Intervall zählt genau davon ab. Deshalb bekommt
     * ein ausgefallener stündlicher Cron-Job trotzdem seinen Termin zur vollen
     * Stunde, während ein „alle 15 Minuten" ab dem tatsächlichen Zeitpunkt neu
     * zählt.
     */
    public function nextAfter(Carbon $after): Carbon
    {
        $local = $after->copy()->setTimezone($this->timezone);

        $next = match ($this->type) {
            CronScheduleType::Crontab => Carbon::instance(
                (new CronExpression((string) $this->expression))
                    ->getNextRunDate($local, 0, false, $this->timezone)
            ),
            CronScheduleType::Interval => $this->intervalUnit?->advance($local, (int) $this->intervalValue)
                ?? $local->copy()->addMinutes((int) $this->intervalValue),
        };

        // Die Sekunden fallen weg: ein Zeitplan ist minutengenau, und ein
        // Rest von 37 Sekunden würde beim Vergleich mit dem Toleranzfenster
        // nur für Verwirrung sorgen.
        return $next->setTimezone('UTC')->startOfMinute();
    }

    /**
     * Lesbare Fassung für Oberfläche und Alarmtext („0 2 * * * (Europe/Berlin)",
     * „alle 15 Minuten").
     */
    public function describe(): string
    {
        if ($this->type === CronScheduleType::Crontab) {
            return "{$this->expression} ({$this->timezone})";
        }

        return __('crons.schedule.every', [
            'value' => (string) $this->intervalValue,
            'unit' => $this->intervalUnit?->label() ?? '',
        ]);
    }

    /**
     * Taugt die Zeichenkette als Cron-Ausdruck?
     *
     * Wird auch von der Eingabeprüfung benutzt — ein unbrauchbarer Ausdruck
     * soll im Formular auffallen und nicht erst dann, wenn der erste Termin
     * ausgerechnet werden müsste.
     */
    public static function isValidExpression(string $expression): bool
    {
        $expression = trim($expression);

        if ($expression === '') {
            return false;
        }

        // `CronExpression` versteht auch Kurzformen wie `@daily`. Die sind
        // erlaubt: sie stehen so in echten Crontabs.
        return CronExpression::isValidExpression($expression);
    }

    public static function isValidTimezone(string $timezone): bool
    {
        return in_array($timezone, DateTimeZone::listIdentifiers(), true);
    }

    /**
     * Eine unbekannte Zeitzone wird zu UTC statt zu einem Fehler: der Wert kann
     * aus einem Check-in stammen, und ein Tippfehler im `monitor_config` eines
     * fremden SDK soll die Überwachung nicht lahmlegen.
     */
    private static function normalizeTimezone(string $timezone): string
    {
        $timezone = trim($timezone);

        return $timezone !== '' && self::isValidTimezone($timezone) ? $timezone : 'UTC';
    }
}
