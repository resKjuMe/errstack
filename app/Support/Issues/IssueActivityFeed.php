<?php

namespace App\Support\Issues;

use App\Enums\IssueIgnoreMode;
use App\Enums\IssueResolveMode;
use App\Models\Issue;
use App\Models\IssueActivity;
use App\Support\Formats;

/**
 * Der Aktivitätsverlauf eines Fehlers für die Anzeige.
 *
 * **Der Satz wird auf dem Server gebildet, nicht im Browser.** Ein Vermerk
 * lautet je nach Art und Bedingung „stummgeschaltet, bis 100 weitere Ereignisse
 * in 60 Minuten" oder „erledigt in Version 1.4.2" — das aus Bausteinen in der
 * Oberfläche zusammenzusetzen hieße, die Beugung zweier Sprachen in JavaScript
 * nachzubauen. Die Übersetzungen liegen ohnehin hier.
 */
final class IssueActivityFeed
{
    /**
     * Wie viele Vermerke die Detailseite zeigt.
     *
     * Der Verlauf ist eine Randnotiz und keine zweite Seite: was älter ist,
     * beantwortet keine Frage mehr, die man vor einem offenen Stacktrace hat.
     */
    public const LIMIT = 20;

    /**
     * @return list<array<string, mixed>>
     */
    public static function forIssue(Issue $issue): array
    {
        return $issue->activities()
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(static fn (IssueActivity $activity): array => self::present($activity))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private static function present(IssueActivity $activity): array
    {
        return [
            'id' => $activity->id,
            'type' => $activity->type->value,
            'text' => self::text($activity),
            // Der Name aus dem Vermerk und nicht aus dem Konto: er ist der zum
            // Zeitpunkt der Handlung, und genau der gehört in einen Verlauf.
            'actor' => $activity->actor_name,
            'at' => $activity->created_at?->toIso8601String(),
            'atLabel' => Formats::dateTime($activity->created_at),
        ];
    }

    /**
     * Der Satz zu einem Vermerk — mit Bedingung, wo es eine gibt.
     */
    private static function text(IssueActivity $activity): string
    {
        $data = $activity->data ?? [];
        $key = 'issues.activity.'.$activity->type->value;

        return __($key, [
            'condition' => self::condition($data),
            'count' => Formats::number((int) ($data['count'] ?? $data['users'] ?? 0)),
            'minutes' => Formats::number((int) ($data['window'] ?? 0)),
        ]);
    }

    /**
     * Die Bedingung in Worten — leer, wo keine mitgegeben wurde.
     *
     * @param  array<string, mixed>  $data
     */
    private static function condition(array $data): string
    {
        $mode = (string) ($data['mode'] ?? '');

        if ($mode === '') {
            return '';
        }

        return match (true) {
            isset($data['users']) => __('issues.actions.condition.users', [
                'count' => Formats::number((int) $data['users']),
            ]),
            isset($data['count'], $data['window']) => __('issues.actions.condition.count_window', [
                'count' => Formats::number((int) $data['count']),
                'minutes' => Formats::number((int) $data['window']),
            ]),
            isset($data['count']) => __('issues.actions.condition.count', [
                'count' => Formats::number((int) $data['count']),
            ]),
            // Ohne Schwelle sagt die Art alles: „dauerhaft", „bis es wieder
            // auftritt", „mit der nächsten Auslieferung".
            default => IssueIgnoreMode::tryFrom($mode)?->label()
                ?? IssueResolveMode::tryFrom($mode)?->label()
                ?? '',
        };
    }
}
