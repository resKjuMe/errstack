<?php

namespace App\Http\Middleware;

use App\Enums\ApiScope;
use App\Support\Api\ApiContext;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Prüft, ob das Token der Anfrage den nötigen Geltungsbereich hat — an der Route
 * hinterlegt als `scope:project:read`.
 *
 * Geprüft wird zweistufig, und beides ist nötig:
 *
 * 1. Steht der Bereich im Token? (`project:write` deckt `project:read` mit ab)
 * 2. Reicht die Rolle des Kontos in der Organisation für diesen Bereich?
 *
 * Ohne Schritt 2 behielte ein persönliches Token die Rechte des Tages, an dem es
 * angelegt wurde: wer von der Verwaltung zum Lesenden herabgestuft wird, dürfte
 * per Token weiter schreiben. Organisationsweite Tokens gehören keinem Konto —
 * für sie entscheidet allein Schritt 1.
 */
class EnsureApiScope
{
    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        $token = ApiContext::token($request);
        $organization = ApiContext::organization($request);
        $actor = ApiContext::actor($request);

        foreach ($scopes as $name) {
            $needed = ApiScope::from($name);

            if (! $token->can($needed->value)) {
                throw new AuthorizationException(
                    "Dem Token fehlt der Geltungsbereich „{$needed->value}“."
                );
            }

            if ($actor === null) {
                continue;
            }

            $role = $organization->roleFor($actor);

            if ($role === null || ! $role->atLeast($needed->minimumRole())) {
                throw new AuthorizationException(
                    __('validation.messages.scope_role_too_low', ['scope' => $needed->value])
                );
            }
        }

        return $next($request);
    }
}
