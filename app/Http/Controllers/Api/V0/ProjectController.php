<?php

namespace App\Http\Controllers\Api\V0;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Project;
use App\Support\Api\ApiQuery;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Die Projekte einer Organisation über die öffentliche Schnittstelle.
 *
 * Zusammen mit den Organisations-Endpunkten zeigt das den Rahmen dieses
 * Schrittes an einer zweiten Ressource: dasselbe Token, das mit `project:read`
 * lesen darf, wird beim Ändern (`project:write`) mit 403 abgewiesen.
 *
 * Die Adresse trägt den Organisations-Slug wie bei Sentry
 * (`/api/0/organizations/{organization}/projects`); dass er zur Organisation des
 * Tokens gehört, stellt EnsureApiOrganization sicher.
 */
class ProjectController extends Controller
{
    public function index(Request $request, Organization $organization): JsonResponse
    {
        $query = Project::query()->where('organization_id', $organization->id);

        $paginator = ApiQuery::paginate($query, $request, [
            'name' => 'name',
            'slug' => 'slug',
            'created_at' => 'created_at',
        ], 'name');

        return ApiResponse::paginated(
            $paginator,
            fn (Project $project): array => self::payload($project),
        );
    }

    public function show(Organization $organization, Project $project): JsonResponse
    {
        return ApiResponse::data(self::payload($project));
    }

    public function update(Request $request, Organization $organization, Project $project): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ], [], ['name' => 'Name']);

        $project->update(['name' => $validated['name']]);

        return ApiResponse::data(self::payload($project));
    }

    /**
     * Der Sicherheits-Token des Projekts steht bewusst nicht darin: er gehört in
     * die Oberfläche, nicht in eine Liste, die jedes `project:read`-Token abrufen
     * darf.
     *
     * @return array<string, string|int|null>
     */
    private static function payload(Project $project): array
    {
        return [
            'slug' => $project->slug,
            'name' => $project->name,
            'platform' => $project->platform->value,
            'default_environment' => $project->default_environment,
            'retention_days' => $project->retention_days,
            'created_at' => $project->created_at?->toIso8601String(),
        ];
    }
}
