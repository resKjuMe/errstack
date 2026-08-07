<?php

namespace App\Enums;

/**
 * Wie der Zeitplan eines überwachten Jobs angegeben ist.
 *
 * Beide Formen kommen in der Praxis vor und lassen sich nicht ineinander
 * überführen: „jede Stunde" ist etwas anderes als „stündlich zur vollen
 * Stunde". Ein Intervall zählt ab dem letzten Lauf, ein Cron-Ausdruck steht
 * fest im Kalender — ein Job, der um 02:00 laufen soll, soll das auch dann,
 * wenn der vorherige Lauf ausgefallen ist.
 *
 * Die Werte sind die aus Sentrys `monitor_config`; sie kommen so über die
 * Check-in-Schnittstelle herein.
 */
enum CronScheduleType: string
{
    /** Ein Cron-Ausdruck: `0 2 * * *`. */
    case Crontab = 'crontab';

    /** Ein Abstand: alle 15 Minuten, alle 6 Stunden. */
    case Interval = 'interval';

    public function label(): string
    {
        return __('enums.cron_schedule_type.'.$this->value);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $type): array => ['value' => $type->value, 'label' => $type->label()],
            self::cases(),
        );
    }
}
