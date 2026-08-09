<?php

namespace App\Support\Ingest\Quotas;

use App\Enums\QuotaCategory;
use App\Enums\QuotaScope;
use App\Models\Project;
use App\Models\Quota;
use Illuminate\Support\Facades\Cache;

/**
 * Die geltenden Grenzen einer Ebene — aus dem Zwischenspeicher, nicht aus der
 * Datenbank.
 *
 * Der Grund ist die Stelle, an der gefragt wird: bei **jeder** eingehenden
 * Meldung, bevor irgendetwas ausgewertet wurde. Eine Abfrage je Meldung wäre
 * ausgerechnet auf dem Weg, den eine Fehlerflut nimmt, eine zusätzliche
 * Datenbanklast — die Begrenzung würde die Last mit verursachen, gegen die sie
 * schützen soll.
 *
 * Geändert wird selten, gelesen ständig; deshalb der lange Vorhalt und das
 * ausdrückliche Vergessen beim Speichern ({@see Quota::booted()}). Fällt der
 * Zwischenspeicher aus, wird neu gelesen — der Fehler geht in die richtige
 * Richtung: es gilt wieder, was in der Datenbank steht.
 */
final class QuotaLimits
{
    /**
     * Wie lange geltende Grenzen vorgehalten werden.
     *
     * Fünf Minuten sind hier keine Frage der Aktualität — eine geänderte
     * Grenze gilt sofort, weil das Speichern den Eintrag vergisst. Sie sind das
     * Auffangnetz für den Fall, dass dieses Vergessen einmal nicht ankommt
     * (mehrere Anwendungsserver mit je eigenem Zwischenspeicher).
     */
    public const CACHE_SECONDS = 300;

    /**
     * Die Grenzen einer Ebene, nach Datenart.
     *
     * @return array<string, array{id: int, month: int|null, minute: int|null}>
     */
    public static function forScope(QuotaScope $scope, int $scopeId): array
    {
        /** @var array<string, array{id: int, month: int|null, minute: int|null}> $limits */
        $limits = Cache::remember(
            self::cacheKey($scope, $scopeId),
            self::CACHE_SECONDS,
            static function () use ($scope, $scopeId): array {
                $rows = [];

                foreach (Quota::forScope($scope, $scopeId) as $category => $quota) {
                    $rows[$category] = [
                        'id' => $quota->id,
                        'month' => $quota->per_month,
                        'minute' => $quota->per_minute,
                    ];
                }

                return $rows;
            },
        );

        return $limits;
    }

    /**
     * Die Grenze einer Ebene für genau eine Datenart.
     *
     * @return array{id: int, month: int|null, minute: int|null}|null
     */
    public static function for(QuotaScope $scope, int $scopeId, QuotaCategory $category): ?array
    {
        return self::forScope($scope, $scopeId)[$category->value] ?? null;
    }

    /**
     * Die Organisation eines Projekts.
     *
     * Ebenfalls vorgehalten, und mit gutem Gewissen: ein Projekt wechselt die
     * Organisation nicht. Ohne diesen Eintrag stünde auf dem Weg jeder Meldung
     * eine Abfrage, deren Antwort sich nie ändert.
     */
    public static function organizationId(int $projectId): ?int
    {
        /** @var int|null $id */
        $id = Cache::remember(
            'quota:project-org:'.$projectId,
            self::CACHE_SECONDS,
            static fn (): ?int => Project::query()->whereKey($projectId)->value('organization_id'),
        );

        return $id;
    }

    public static function forget(QuotaScope $scope, int $scopeId): void
    {
        Cache::forget(self::cacheKey($scope, $scopeId));
    }

    private static function cacheKey(QuotaScope $scope, int $scopeId): string
    {
        return 'quota:limits:'.$scope->value.':'.$scopeId;
    }
}
