<?php

namespace App\Enums;

/**
 * Der Zustand, nach dem der Alarm-Verlauf eingeschränkt wird.
 *
 * Er ist absichtlich **nicht** {@see AlertStatus}: der Verlauf führt zwei Arten
 * von Einträgen zusammen, und nur die eine hat einen Zustand. Ein
 * Schwellwert-Alarm (A3) wechselt zwischen „in Ordnung", „Warnung" und
 * „kritisch"; eine Fehler-Regel (A2) hat davon nichts — sie löst aus oder nicht.
 *
 * Diese Aufzählung ist deshalb das, was in der Liste tatsächlich zu sehen ist:
 * vier Ergebnisse, von denen drei aus dem Zustandswechsel kommen und eines aus
 * der Auslösung. Wer nach „kritisch" filtert, bekommt keine Fehler-Auslösungen —
 * nicht weil sie unwichtig wären, sondern weil sie nicht kritisch **sind**.
 */
enum AlertHistoryState: string
{
    case All = 'all';

    /** Eine Fehler-Regel hat gegriffen. */
    case Fired = 'fired';

    case Warning = 'warning';

    case Critical = 'critical';

    /** Ein Schwellwert-Alarm ist wieder in Ordnung. */
    case Resolved = 'resolved';

    public static function default(): self
    {
        return self::All;
    }

    public function label(): string
    {
        return __('alert_overview.states.'.$this->value);
    }

    /**
     * Gehören Auslösungen von Fehler-Regeln dazu?
     */
    public function includesIssueTriggers(): bool
    {
        return $this === self::All || $this === self::Fired;
    }

    /**
     * Der Zielzustand, auf den Zustandswechsel eingeschränkt werden — `null`,
     * wenn Zustandswechsel gar nicht dazugehören.
     */
    public function transitionStatus(): ?AlertStatus
    {
        return match ($this) {
            self::All => null,
            self::Fired => null,
            self::Warning => AlertStatus::Warning,
            self::Critical => AlertStatus::Critical,
            self::Resolved => AlertStatus::Ok,
        };
    }

    /**
     * Gehören Zustandswechsel dazu?
     */
    public function includesTransitions(): bool
    {
        return $this !== self::Fired;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $state): array => ['value' => $state->value, 'label' => $state->label()],
            self::cases(),
        );
    }
}
