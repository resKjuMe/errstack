<?php

namespace App\Enums;

/**
 * Für wen eine Stummschaltung gilt.
 *
 * Zwei Werte, und der Unterschied ist keine Feinheit, sondern die
 * Rechtefrage: {@see self::Everyone} nimmt die Regel für die ganze
 * Organisation vom Netz und darf deshalb nur von der Verwaltung gesetzt
 * werden; {@see self::Personal} betrifft nur den, der klickt, und ist damit
 * eine Einstellung wie jede andere persönliche auch.
 */
enum AlertSnoozeScope: string
{
    /** Niemand bekommt mehr etwas — auch die gemeinsamen Kanäle schweigen. */
    case Everyone = 'everyone';

    /** Nur die eigenen Benachrichtigungen; die gemeinsamen Kanäle melden weiter. */
    case Personal = 'personal';

    public function label(): string
    {
        return __('alert_overview.scopes.'.$this->value);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $scope): array => ['value' => $scope->value, 'label' => $scope->label()],
            self::cases(),
        );
    }
}
