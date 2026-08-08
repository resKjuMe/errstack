<?php

namespace App\Support\Operations;

use App\Jobs\ProcessIngestPayload;
use App\Models\IngestPayload;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reiht endgültig gescheiterte Meldungen erneut zur Verarbeitung ein.
 *
 * Herausgezogen aus `ingest:retry`, weil es die Betriebsansicht genauso
 * braucht: dieselbe Reihenfolge (erst zurückstellen, dann einreihen), dieselbe
 * Obergrenze. Zwei Stellen, die dasselbe fast gleich tun, gehen genau an der
 * Reihenfolge auseinander — und dieser Unterschied fällt nur unter Last auf.
 */
final class IngestRetry
{
    /**
     * Wie viele Meldungen ein Aufruf höchstens einreiht.
     *
     * Eine Obergrenze und kein „alle": nach einem längeren Ausfall liegen
     * zehntausende da, und sie in einem Rutsch einzureihen bringt genau den
     * Rückstand zurück, den man gerade abbaut. Wer mehr will, ruft erneut auf.
     */
    public const DEFAULT_LIMIT = 1000;

    /**
     * @param  list<int|string>  $ids  Nur diese Meldungen; leer heißt: alle passenden.
     * @return int Wie viele erneut eingereiht wurden.
     */
    public function queueFailed(?int $projectId = null, array $ids = [], int $limit = self::DEFAULT_LIMIT): int
    {
        $payloads = IngestPayload::query()
            ->failedProcessing()
            ->when($projectId !== null, fn (Builder $query) => $query->where('project_id', $projectId))
            ->when($ids !== [], fn (Builder $query) => $query->whereIn('id', $ids))
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();

        foreach ($payloads as $payload) {
            // Erst zurückstellen, dann einreihen: ein Arbeiter, der den Job
            // sofort abholt, sieht sonst noch den alten Zustand und hält die
            // Meldung für erledigt.
            $payload->resetProcessing();

            ProcessIngestPayload::dispatch($payload);
        }

        return $payloads->count();
    }
}
