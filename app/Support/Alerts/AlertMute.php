<?php

namespace App\Support\Alerts;

use App\Models\AlertSnooze;
use App\Models\IssueAlertRule;
use App\Models\MetricAlert;
use Carbon\CarbonImmutable;

/**
 * Wer eine Regel gerade nicht hören will — als eine Auskunft statt als eine
 * Abfrage je Empfänger.
 *
 * Die Stummschaltungen einer Regel sind eine Handvoll Zeilen, aber sie werden an
 * der teuersten Stelle gebraucht: im Versandweg, nachdem eine Regel gegriffen
 * hat, und dort einmal für den Kanal und einmal je Mitglied. Diese Klasse liest
 * sie **einmal** und beantwortet danach jede Frage aus dem Gedächtnis.
 *
 * **Zwei Geltungsbereiche, zwei Wirkungen:**
 *
 * - *Für alle* schweigt die Regel vollständig — auch die gemeinsamen Kanäle.
 * - *Nur für mich* betrifft ausschließlich die persönlichen Benachrichtigungen
 *   (A5). Ein gemeinsamer Kanal ist an niemanden persönlich gerichtet; ihn für
 *   eine einzelne Person stummzuschalten hieße, ihn für alle stummzuschalten,
 *   und das ist die andere Einstellung.
 *
 * Daraus folgt die Ehrlichkeit, die die Oberfläche zeigen muss: eine Regel, die
 * nur an gemeinsame Kanäle meldet — jeder Schwellwert-Alarm aus A3 —, wird von
 * einer persönlichen Stummschaltung nicht leiser.
 */
final class AlertMute
{
    /**
     * @param  array<int, AlertSnooze>  $personal  je Person die Stummschaltung, die am längsten reicht
     */
    private function __construct(
        public readonly ?AlertSnooze $everyone,
        public readonly array $personal,
    ) {}

    /**
     * Die Stummschaltungen einer einzelnen Regel — der Weg des Versands.
     */
    public static function for(MetricAlert|IssueAlertRule $subject, ?CarbonImmutable $now = null): self
    {
        $column = $subject instanceof MetricAlert ? 'metric_alert_id' : 'issue_alert_rule_id';

        return self::many($column, [(int) $subject->getKey()], $now)[(int) $subject->getKey()]
            ?? new self(null, []);
    }

    /**
     * Die Stummschaltungen vieler Schwellwert-Alarme auf einmal — der Weg der
     * Übersicht.
     *
     * @param  list<int>  $ids
     * @return array<int, self> nach Alarm-Kennung
     */
    public static function forMetricAlerts(array $ids, ?CarbonImmutable $now = null): array
    {
        return self::many('metric_alert_id', $ids, $now);
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, self> nach Regel-Kennung
     */
    public static function forIssueAlertRules(array $ids, ?CarbonImmutable $now = null): array
    {
        return self::many('issue_alert_rule_id', $ids, $now);
    }

    /**
     * Schweigt die Regel für jeden?
     */
    public function silent(): bool
    {
        return $this->everyone !== null;
    }

    /**
     * Bekommt diese Person gerade nichts?
     *
     * Ohne Person gefragt: nur die Stummschaltung für alle zählt — genau das
     * braucht der Versand an einen gemeinsamen Kanal.
     */
    public function mutes(?int $userId = null): bool
    {
        if ($this->silent()) {
            return true;
        }

        return $userId !== null && isset($this->personal[$userId]);
    }

    /**
     * Die eigene Stummschaltung dieser Person, falls sie eine gesetzt hat.
     */
    public function mine(int $userId): ?AlertSnooze
    {
        return $this->personal[$userId] ?? null;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, self>
     */
    private static function many(string $column, array $ids, ?CarbonImmutable $now): array
    {
        if ($ids === []) {
            return [];
        }

        $now ??= CarbonImmutable::now();

        $rows = AlertSnooze::query()
            ->active($now)
            ->whereIn($column, $ids)
            ->with('createdBy:id,name')
            // Die längste zuerst: von zwei Zeilen desselben Geltungsbereichs —
            // die es nur bei zwei gleichzeitigen Klicks überhaupt gibt — gilt
            // die, die weiter reicht. Alles andere wäre eine Ruhe, die früher
            // endet, als jemand eingestellt hat.
            ->orderByDesc('until')
            ->get();

        $everyone = [];
        $personal = [];

        foreach ($rows as $row) {
            $key = (int) $row->getAttribute($column);

            if ($row->forEveryone()) {
                $everyone[$key] ??= $row;

                continue;
            }

            $personal[$key][(int) $row->user_id] ??= $row;
        }

        $mutes = [];

        foreach ($ids as $id) {
            $mutes[$id] = new self($everyone[$id] ?? null, $personal[$id] ?? []);
        }

        return $mutes;
    }
}
