<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectKey;

/**
 * Nutzlast der Schlüssel-Seite. Sie wird nur denen ausgeliefert, die die
 * Schlüssel auch verwalten dürfen (siehe ProjectKeyController) — die DSN steht
 * hier deshalb im Klartext.
 */
final class ProjectKeyData
{
    /**
     * @return array<string, mixed>
     */
    public static function index(Project $project): array
    {
        $project->loadMissing('organization');
        $organization = $project->organization;

        $keys = $project->keys()->orderBy('id')->get();

        return [
            'project' => [
                'slug' => $project->slug,
                'name' => $project->name,
                'href' => route('projects.show', [$organization, $project]),
                'keysHref' => route('projects.keys.index', [$organization, $project]),
            ],
            'organization' => [
                'slug' => $organization->slug,
                'name' => $organization->name,
                'href' => route('organizations.show', $organization),
            ],
            'keys' => $keys
                ->map(fn (ProjectKey $key): array => self::key($organization, $project, $key))
                ->all(),
            // Der letzte Schlüssel bleibt stehen, damit das Projekt eine
            // Adresse behält; die Oberfläche blendet den Knopf entsprechend aus.
            'canDelete' => $keys->count() > 1,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function key(Organization $organization, Project $project, ProjectKey $key): array
    {
        $parameters = [$organization, $project, $key];

        return [
            'id' => $key->id,
            'name' => $key->name,
            'publicKey' => $key->public_key,
            'dsn' => $key->dsn(),
            'active' => $key->active,
            'rateLimitPerMinute' => $key->rate_limit_per_minute,
            'href' => route('projects.keys.update', $parameters),
            'toggleHref' => route('projects.keys.toggle', $parameters),
            'rotateHref' => route('projects.keys.rotate', $parameters),
        ];
    }
}
