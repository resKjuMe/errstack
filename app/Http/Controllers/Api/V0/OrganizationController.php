<?php

namespace App\Http\Controllers\Api\V0;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Support\Api\ApiContext;
use App\Support\Api\ApiQuery;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Beispiel-Endpunkte der öffentlichen Schnittstelle, an den Organisationen
 * gezeigt: eine geblätterte Liste, ein Einzelabruf und eine Änderung.
 *
 * Sie sind bewusst schmal — dieser Schritt liefert den Rahmen (Tokens,
 * Geltungsbereiche, Fehlerformat, Blätterung, Ratenbegrenzung), die fachlichen
 * Endpunkte kommen mit dem jeweiligen Feature und dann vollständig mit X5.
 *
 * Am Zusammenspiel von `index` (Bereich `org:read`) und `update` (`org:write`)
 * lässt sich der Rahmen von außen nachvollziehen: dasselbe Token, das lesen
 * darf, wird beim Ändern mit 403 abgewiesen.
 */
class OrganizationController extends Controller
{
    /**
     * Die Organisationen, die dieses Token sehen darf. Das ist immer genau eine:
     * ein Token gilt für eine Organisation. Die Liste bleibt trotzdem eine Liste,
     * damit Clients nicht zwei Antwortformen kennen müssen — und weil Sentry
     * dieselbe Adresse als Liste führt.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Organization::query()->whereKey(ApiContext::organization($request)->id);

        $paginator = ApiQuery::paginate($query, $request, [
            'name' => 'name',
            'slug' => 'slug',
            'created_at' => 'created_at',
        ], 'name');

        return ApiResponse::paginated(
            $paginator,
            fn (Organization $organization): array => self::payload($organization),
        );
    }

    public function show(Organization $organization): JsonResponse
    {
        return ApiResponse::data(self::payload($organization));
    }

    public function update(Request $request, Organization $organization): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ], [], ['name' => 'Name']);

        $organization->update(['name' => $validated['name']]);

        return ApiResponse::data(self::payload($organization));
    }

    /**
     * @return array<string, string|null>
     */
    private static function payload(Organization $organization): array
    {
        return [
            'slug' => $organization->slug,
            'name' => $organization->name,
            'created_at' => $organization->created_at?->toIso8601String(),
        ];
    }
}
