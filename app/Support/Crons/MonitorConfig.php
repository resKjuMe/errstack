<?php

namespace App\Support\Crons;

use App\Enums\CronIntervalUnit;
use App\Enums\CronScheduleType;

/**
 * Die Selbstbeschreibung eines Jobs aus `monitor_config` eines Check-ins.
 *
 * Sie ist der Grund, warum ein Monitor nicht vorher von Hand angelegt werden
 * muss: das SDK bringt seinen Zeitplan mit, und die Überwachung entsteht beim
 * ersten Lauf. Das ist bei Cronjobs der praktikablere Weg — der Zeitplan steht
 * ohnehin im Code, und ihn ein zweites Mal in eine Oberfläche zu tippen heißt,
 * dass beide Stellen auseinanderlaufen.
 *
 * Deshalb wird sie bei jedem Check-in erneut angewandt: ändert jemand den
 * Zeitplan im Code, zieht die Überwachung mit. Was **nicht** aus der Config
 * kommt (weil das SDK es nicht mitschickt), bleibt unangetastet — sonst würde
 * eine in der Oberfläche eingestellte Toleranz beim nächsten Lauf
 * zurückgesetzt.
 */
final readonly class MonitorConfig
{
    private function __construct(
        public ?CronSchedule $schedule,
        public ?int $checkinMarginMinutes,
        public ?int $maxRuntimeMinutes,
        public ?int $failureTolerance,
        public ?int $recoveryTolerance,
    ) {}

    /**
     * `null`, wenn gar keine Konfiguration dabei war — das unterscheidet
     * „nichts gesagt" von „alles auf Vorgabe".
     */
    public static function fromArray(mixed $data): ?self
    {
        if (! is_array($data) || $data === []) {
            return null;
        }

        $timezone = is_string($data['timezone'] ?? null) ? $data['timezone'] : 'UTC';

        return new self(
            schedule: self::schedule($data['schedule'] ?? null, $timezone),
            checkinMarginMinutes: self::minutes($data['checkin_margin'] ?? null),
            maxRuntimeMinutes: self::minutes($data['max_runtime'] ?? null),
            failureTolerance: self::threshold($data['failure_issue_threshold'] ?? null),
            recoveryTolerance: self::threshold($data['recovery_threshold'] ?? null),
        );
    }

    /**
     * Bringt die Konfiguration überhaupt etwas Verwertbares mit?
     *
     * Ein `monitor_config` ohne lesbaren Zeitplan reicht nicht, um einen
     * Monitor anzulegen: ohne Zeitplan gäbe es keinen Termin, und ohne Termin
     * ließe sich nie feststellen, dass eine Ausführung ausgeblieben ist.
     */
    public function hasSchedule(): bool
    {
        return $this->schedule !== null;
    }

    /**
     * Die Felder, die diese Konfiguration tatsächlich benennt — als
     * Änderungssatz für den Monitor. Was das SDK nicht mitgeschickt hat, fehlt
     * hier und bleibt dadurch, wie es war.
     *
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        $attributes = [];

        if ($this->schedule !== null) {
            $attributes += [
                'schedule_type' => $this->schedule->type,
                'schedule_expression' => $this->schedule->expression,
                'interval_value' => $this->schedule->intervalValue,
                'interval_unit' => $this->schedule->intervalUnit,
                'timezone' => $this->schedule->timezone,
            ];
        }

        if ($this->checkinMarginMinutes !== null) {
            $attributes['checkin_margin_minutes'] = $this->checkinMarginMinutes;
        }

        if ($this->maxRuntimeMinutes !== null) {
            $attributes['max_runtime_minutes'] = $this->maxRuntimeMinutes;
        }

        if ($this->failureTolerance !== null) {
            $attributes['failure_tolerance'] = $this->failureTolerance;
        }

        if ($this->recoveryTolerance !== null) {
            $attributes['recovery_tolerance'] = $this->recoveryTolerance;
        }

        return $attributes;
    }

    /**
     * Sentry kennt zwei Schreibweisen für den Zeitplan, und beide sind im
     * Umlauf: die ausführliche als Objekt und die kurze als nackter
     * Cron-Ausdruck (`"schedule": "0 2 * * *"`).
     */
    private static function schedule(mixed $schedule, string $timezone): ?CronSchedule
    {
        if (is_string($schedule)) {
            return CronSchedule::isValidExpression($schedule)
                ? CronSchedule::crontab($schedule, $timezone)
                : null;
        }

        if (! is_array($schedule)) {
            return null;
        }

        $type = CronScheduleType::tryFrom(is_string($schedule['type'] ?? null) ? $schedule['type'] : '');
        $value = $schedule['value'] ?? null;

        if ($type === CronScheduleType::Interval) {
            $unit = CronIntervalUnit::tryFrom(is_string($schedule['unit'] ?? null) ? $schedule['unit'] : '');

            return $unit !== null && is_int($value) && $value >= 1
                ? CronSchedule::interval($value, $unit, $timezone)
                : null;
        }

        // Ohne `type` gilt ein Ausdruck als Cron-Ausdruck: das ist die einzige
        // Form, die ohne Einheit auskommt.
        return is_string($value) && CronSchedule::isValidExpression($value)
            ? CronSchedule::crontab($value, $timezone)
            : null;
    }

    /**
     * Eine Minutenangabe aus der Konfiguration. Die Obergrenze ist ein Jahr —
     * darüber ist es kein Toleranzfenster mehr, sondern ein Tippfehler.
     */
    private static function minutes(mixed $value): ?int
    {
        if (! is_int($value) || $value < 0 || $value > 525600) {
            return null;
        }

        return $value;
    }

    private static function threshold(mixed $value): ?int
    {
        if (! is_int($value) || $value < 1 || $value > 1000) {
            return null;
        }

        return $value;
    }
}
