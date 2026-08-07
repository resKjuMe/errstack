<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Support\Api\ApiContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Stellt sicher, dass die Organisation aus der Adresse die des Tokens ist.
 *
 * Die Adressen tragen den Organisations-Slug (`/api/0/organizations/acme/…`) —
 * dieselbe Form wie bei Sentry, damit vorhandene Werkzeuge damit umgehen können.
 * Ein Token gilt aber immer nur für genau eine Organisation; ohne diese Prüfung
 * wäre der Slug in der Adresse ein Türöffner zu fremden Daten.
 *
 * Antwort ist bewusst 404 und nicht 403: ob es eine Organisation mit diesem
 * Namen überhaupt gibt, ist selbst schon eine Auskunft.
 */
class EnsureApiOrganization
{
    public function handle(Request $request, Closure $next): Response
    {
        $fromRoute = $request->route('organization');
        $expected = ApiContext::organization($request);

        // Beide Formen abdecken: je nach Reihenfolge der Middleware steht im
        // Routen-Parameter schon das Modell oder noch der rohe Slug. Auf eine
        // bestimmte Reihenfolge zu setzen wäre hier besonders unangenehm — bei
        // der falschen Annahme fiele die Prüfung stillschweigend aus.
        $matches = match (true) {
            $fromRoute instanceof Organization => $fromRoute->is($expected),
            is_string($fromRoute) => $fromRoute === $expected->getRouteKey(),
            default => true,
        };

        if (! $matches) {
            throw new NotFoundHttpException('Nicht gefunden.');
        }

        return $next($request);
    }
}
