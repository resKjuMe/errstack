<?php

namespace App\Http\Controllers\Api\V0;

use App\Enums\ApiScope;
use App\Http\Controllers\Controller;
use App\Support\Api\ApiContext;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Einstieg der Schnittstelle: `GET /api/0/`.
 *
 * Sagt, welche Version antwortet und was das mitgeschickte Token darf. Damit
 * lässt sich ein Zugang in einem einzigen Aufruf prüfen, ohne einen fachlichen
 * Endpunkt zu treffen — der erste Griff bei „das Token geht nicht".
 */
class RootController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $token = ApiContext::token($request);
        $organization = ApiContext::organization($request);
        $actor = ApiContext::actor($request);

        return ApiResponse::data([
            'version' => (string) config('api.version'),
            'organization' => [
                'slug' => $organization->slug,
                'name' => $organization->name,
            ],
            'token' => [
                'name' => $token->name,
                'kind' => $token->kind()->value,
                'scopes' => array_map(fn (ApiScope $scope): string => $scope->value, $token->scopes()),
                'expires_at' => $token->expires_at?->toIso8601String(),
            ],
            // Bei einem organisationsweiten Token gibt es kein handelndes Konto.
            'actor' => $actor === null ? null : [
                'id' => $actor->id,
                'name' => $actor->name,
                'email' => $actor->email,
            ],
        ]);
    }
}
