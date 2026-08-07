<?php

namespace App\Enums;

/**
 * Auflösungs-Verhalten eines Projekts: ob ein Issue offen bleibt, bis es jemand
 * von Hand schließt, oder nach einer Zeit ohne neues Auftreten von selbst als
 * erledigt gilt. Ausgewertet wird das mit den Issues ab Phase P1 — hier steht
 * nur die Einstellung.
 */
enum ResolutionBehavior: string
{
    case Manual = 'manual';
    case AfterWeek = 'after_week';
    case AfterMonth = 'after_month';

    public function label(): string
    {
        return __('enums.resolution_behavior.'.$this->value);
    }

    /**
     * Tage ohne neues Auftreten, nach denen automatisch aufgelöst wird —
     * null, wenn das nur von Hand geschieht.
     */
    public function inactivityDays(): ?int
    {
        return match ($this) {
            self::Manual => null,
            self::AfterWeek => 7,
            self::AfterMonth => 30,
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $behavior) => ['value' => $behavior->value, 'label' => $behavior->label()],
            self::cases(),
        );
    }
}
