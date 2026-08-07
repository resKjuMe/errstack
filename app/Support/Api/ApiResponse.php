<?php

namespace App\Support\Api;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

/**
 * Einheitliche Hülle aller Antworten der öffentlichen Schnittstelle.
 *
 * Die Nutzlast steht immer unter `data` — nie auf oberster Ebene. Das klingt
 * nach Ballast, macht aber jede Antwort erweiterbar: `meta` (Blätterung) und
 * später weitere Felder kommen daneben, ohne dass ein Client, der `data` liest,
 * etwas merkt. Fehler haben dieselbe feste Form, siehe ApiErrors.
 */
final class ApiResponse
{
    /**
     * @param  array<string, string>  $headers
     */
    public static function data(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse(['data' => $data], $status, $headers);
    }

    /**
     * Geblätterte Liste: `data` ist die aktuelle Seite, `meta` sagt, wo man
     * steht. `has_more` ist Bequemlichkeit für Clients, die nur „weiterblättern
     * bis Schluss" wollen und nicht rechnen möchten.
     *
     * @template TItem
     * @template TMapped
     *
     * @param  LengthAwarePaginator<int, TItem>  $paginator
     * @param  callable(TItem): TMapped  $map
     */
    public static function paginated(LengthAwarePaginator $paginator, callable $map): JsonResponse
    {
        return new JsonResponse([
            'data' => array_values(array_map($map, $paginator->items())),
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'total_pages' => $paginator->lastPage(),
                'has_more' => $paginator->hasMorePages(),
            ],
        ]);
    }

    /**
     * Erfolgreich, aber ohne Inhalt (Löschen). Bewusst 204 statt „204 mit
     * leerem data" — ein Client, der auf `data` zugreift, soll hier gar nichts
     * erwarten.
     */
    public static function noContent(): JsonResponse
    {
        return new JsonResponse(null, 204);
    }
}
