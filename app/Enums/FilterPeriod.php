<?php

namespace App\Enums;

/**
 * Zeitraum der globalen Filterleiste. Die relativen Zeiträume sind der Regelfall
 * — sie stehen als Kürzel in der Adresszeile (`?period=24h`) und werden bei jedem
 * Aufruf serverseitig neu aufgelöst. Ein geteilter Link auf „letzte 24 Stunden"
 * zeigt beim Empfänger deshalb dessen letzte 24 Stunden, nicht die des Absenders.
 *
 * `Custom` ist die Ausnahme: dort stehen Anfang und Ende als Datum daneben.
 */
enum FilterPeriod: string
{
    case LastHour = '1h';
    case Last24Hours = '24h';
    case Last7Days = '7d';
    case Last14Days = '14d';
    case Last30Days = '30d';
    case Last90Days = '90d';
    case Custom = 'custom';

    /**
     * Voreinstellung, wenn in der Adresszeile nichts steht.
     */
    public static function default(): self
    {
        return self::Last24Hours;
    }

    public function label(): string
    {
        return __('enums.filter_period.'.$this->value);
    }

    /**
     * Länge des relativen Zeitraums in Stunden — null beim eigenen Zeitraum,
     * dessen Grenzen aus den Datumsfeldern kommen.
     */
    public function hours(): ?int
    {
        return match ($this) {
            self::LastHour => 1,
            self::Last24Hours => 24,
            self::Last7Days => 7 * 24,
            self::Last14Days => 14 * 24,
            self::Last30Days => 30 * 24,
            self::Last90Days => 90 * 24,
            self::Custom => null,
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $period) => ['value' => $period->value, 'label' => $period->label()],
            self::cases(),
        );
    }
}
