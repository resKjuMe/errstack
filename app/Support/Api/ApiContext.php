<?php

namespace App\Support\Api;

use App\Enums\ApiScope;
use App\Http\Middleware\ResolveApiToken;
use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Zugriff auf das Token der laufenden Anfrage: welches Token, welche
 * Organisation, welches Konto.
 *
 * Hinterlegt wird das von {@see ResolveApiToken}; hier steht nur das Auslesen.
 * Der Umweg über die Anfrage-Attribute statt über `$request->user()` hat einen
 * Grund: bei einem organisationsweiten Token ist der Träger die Organisation,
 * und die ist kein anmeldbares Konto. Wer hier fragt, bekommt getrennt, was er
 * braucht — nie ein „Konto", das gar keines ist.
 */
final class ApiContext
{
    public const TOKEN = 'errstack.api_token';

    public const ORGANIZATION = 'errstack.api_organization';

    public const ACTOR = 'errstack.api_actor';

    public static function token(Request $request): ApiToken
    {
        $token = $request->attributes->get(self::TOKEN);

        if (! $token instanceof ApiToken) {
            // Kann nur passieren, wenn eine Route ohne ResolveApiToken läuft.
            throw new RuntimeException('Kein API-Token an der Anfrage: fehlt die Middleware '.ResolveApiToken::class.'?');
        }

        return $token;
    }

    /**
     * Die Organisation, für die das Token gilt. Jede Abfrage der Schnittstelle
     * grenzt darauf ein — ein Token sieht nie über seine Organisation hinaus.
     */
    public static function organization(Request $request): Organization
    {
        $organization = $request->attributes->get(self::ORGANIZATION);

        if (! $organization instanceof Organization) {
            throw new RuntimeException('Keine Organisation an der Anfrage: fehlt die Middleware '.ResolveApiToken::class.'?');
        }

        return $organization;
    }

    /**
     * Das handelnde Konto — oder null bei einem organisationsweiten Token, das
     * niemandem gehört. Wer eine Aktion einem Menschen zuschreiben will (etwa im
     * Änderungsprotokoll), muss mit diesem null rechnen.
     */
    public static function actor(Request $request): ?User
    {
        $actor = $request->attributes->get(self::ACTOR);

        return $actor instanceof User ? $actor : null;
    }

    /**
     * @return list<ApiScope>
     */
    public static function scopes(Request $request): array
    {
        return self::token($request)->scopes();
    }
}
