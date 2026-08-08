<?php

namespace App\Http\Controllers\Api\V0;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Repository;
use App\Support\Api\ApiQuery;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Die verbundenen Repositories einer Organisation über die öffentliche
 * Schnittstelle.
 *
 * Der Endpunkt ist für den Fall gedacht, in dem eine Bauumgebung ihre
 * Repositories selbst anmeldet, statt dass jemand sie in der Oberfläche
 * einträgt. Er ist deshalb — wie das Ankündigen einer Version —
 * **wiederholbar**: dasselbe Repository noch einmal zu verbinden ist kein
 * Fehler, sondern eine Ergänzung.
 *
 * An der Organisation und nicht am Projekt: dasselbe Repository versorgt in
 * aller Regel mehrere Projekte, und der Bezug zu einem entsteht über die
 * Auslieferung, in der seine Commits stecken.
 */
class RepositoryController extends Controller
{
    public function index(Request $request, Organization $organization): JsonResponse
    {
        $query = Repository::query()->where('organization_id', $organization->id);

        $paginator = ApiQuery::paginate($query, $request, [
            'name' => 'name',
            'created_at' => 'created_at',
        ], 'name');

        return ApiResponse::paginated(
            $paginator,
            fn (Repository $repository): array => self::payload($repository),
        );
    }

    public function store(Request $request, Organization $organization): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:'.Repository::NAME_LIMIT],
            'url' => ['nullable', 'string', 'url', 'max:500'],
            'external_id' => ['nullable', 'string', 'max:200'],
        ], [], [
            'name' => 'Name',
            'url' => 'Adresse',
            'external_id' => 'Kennung beim Anbieter',
        ]);

        $name = Repository::normalizeName($validated['name']);

        if ($name === null) {
            throw ValidationException::withMessages([
                'name' => __('validation.required', ['attribute' => 'Name']),
            ]);
        }

        $repository = Repository::forName($organization, $name);
        $created = $repository->wasRecentlyCreated;

        // Nur ausdrücklich mitgeschickte Felder — wie beim Ankündigen einer
        // Version: ein `null` aus einem weggelassenen Feld würde beim zweiten
        // Aufruf eine bereits gesetzte Adresse wieder leeren.
        $changes = array_intersect_key($validated, array_flip(['url', 'external_id']));

        if ($changes !== []) {
            $repository->fill($changes)->save();
        }

        return ApiResponse::data(self::payload($repository), $created ? 201 : 200);
    }

    /**
     * @return array<string, mixed>
     */
    private static function payload(Repository $repository): array
    {
        return [
            'name' => $repository->name,
            'provider' => $repository->provider,
            'url' => $repository->url,
            'external_id' => $repository->external_id,
            'created_at' => $repository->created_at?->toIso8601String(),
        ];
    }
}
