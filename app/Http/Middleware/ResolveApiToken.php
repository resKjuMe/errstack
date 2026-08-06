<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\User;
use App\Support\Api\ApiContext;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Legt das Token der Anfrage, seine Organisation und das handelnde Konto an der
 * Anfrage ab, damit Controller und Middleware sie getypt lesen können
 * ({@see ApiContext}).
 *
 * Läuft nach `auth:sanctum`: die Echtheit des Tokens und seine Frist hat Sanctum
 * dann schon geprüft. Hier kommt hinzu, was Sanctum nicht wissen kann — dass ein
 * persönliches Token nur so lange gilt, wie sein Besitzer der Organisation
 * angehört. Ohne diese Prüfung würde ein Token weiterlaufen, nachdem jemand aus
 * der Organisation entfernt wurde; das Widerrufen jedes einzelnen Tokens von Hand
 * wäre eine Aufgabe, die zuverlässig vergessen wird.
 */
class ResolveApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|Organization|null $tokenable Träger des Tokens — Konto oder Organisation */
        $tokenable = $request->user();

        $token = $tokenable?->currentAccessToken();

        if ($tokenable === null || ! $token instanceof ApiToken) {
            throw new AuthenticationException;
        }

        $organization = $token->organization;
        $actor = $tokenable instanceof User ? $tokenable : null;

        // 403 statt 401: das Token selbst ist echt und nicht abgelaufen — es
        // fehlt die Berechtigung. Für den Aufrufer ist das der Unterschied
        // zwischen „neues Token holen" und „Zugang zur Organisation klären".
        if ($actor !== null && ! $organization->hasMember($actor)) {
            throw new AuthorizationException('Das Konto gehört dieser Organisation nicht mehr an.');
        }

        $request->attributes->set(ApiContext::TOKEN, $token);
        $request->attributes->set(ApiContext::ORGANIZATION, $organization);
        $request->attributes->set(ApiContext::ACTOR, $actor);

        // Ab hier ist `$request->user()` entweder das handelnde Konto oder null.
        // Ohne das Umsetzen gäbe ein organisationsweites Token eine Organisation
        // zurück, wo alles Weitere ein Konto erwartet.
        $request->setUserResolver(fn (): ?User => $actor);

        return $next($request);
    }
}
