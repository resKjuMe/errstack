<?php

namespace App\Enums;

/**
 * Der Zustand eines Schwellwert-Alarms.
 *
 * Drei Stufen und nicht zwei: „auffällig" und „kaputt" sind verschiedene Lagen,
 * und wer sie zusammenlegt, muss sich zwischen einem Alarm entscheiden, der zu
 * oft schrillt, und einem, der zu spät kommt.
 *
 * Der Zustand steht **am Alarm** und nicht in einer Auswertung: erst dadurch
 * lässt sich ein Übergang von einem Dauerzustand unterscheiden — und nur der
 * Übergang wird gemeldet.
 */
enum AlertStatus: string
{
    /** Alles im Rahmen. */
    case Ok = 'ok';

    /** Die Warnschwelle ist überschritten. */
    case Warning = 'warning';

    /** Die kritische Schwelle ist überschritten. */
    case Critical = 'critical';

    public function label(): string
    {
        return __('enums.alert_status.'.$this->value);
    }

    /**
     * Wie dringend eine Meldung über diesen Zustand ist.
     *
     * Die Entwarnung ist ausdrücklich eine Information und keine Warnung: sie
     * soll gelesen und nicht bearbeitet werden.
     */
    public function notificationLevel(): NotificationLevel
    {
        return match ($this) {
            self::Ok => NotificationLevel::Info,
            self::Warning => NotificationLevel::Warning,
            self::Critical => NotificationLevel::Error,
        };
    }

    /**
     * Rangfolge, um zwei Zustände zu vergleichen.
     *
     * Sie ist der Grund, warum sich „ausgelöst", „verschärft" und „entspannt"
     * überhaupt auseinanderhalten lassen: der Übergang ist erst durch die
     * Richtung beschrieben, nicht durch das Ziel allein.
     */
    public function severity(): int
    {
        return match ($this) {
            self::Ok => 0,
            self::Warning => 1,
            self::Critical => 2,
        };
    }

    public function isFiring(): bool
    {
        return $this !== self::Ok;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
