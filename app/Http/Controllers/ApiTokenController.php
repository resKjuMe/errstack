<?php

namespace App\Http\Controllers;

use App\Enums\ApiTokenKind;
use App\Http\Requests\StoreApiTokenRequest;
use App\Models\ApiToken;
use App\Support\ApiTokenData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Verwaltung der API-Tokens in der Oberfläche: auflisten, anlegen, widerrufen.
 * Immer für die gerade aktive Organisation — ein Token gilt für genau eine.
 *
 * Der Klartext-Wert wird genau einmal gezeigt, direkt nach dem Anlegen. Danach
 * ist er unwiederbringlich weg, weil in der Datenbank nur sein Abdruck steht.
 * Übergeben wird er über die Session (Flash) und nicht in der Adresse: dort
 * landete er im Verlauf des Browsers und in jedem Server-Log.
 */
class ApiTokenController extends Controller
{
    public function index(Request $request): InertiaResponse|RedirectResponse
    {
        $user = $request->user();
        $organization = $user->resolveCurrentOrganization();

        // Ohne Organisation gibt es nichts, wofür ein Token gelten könnte.
        if ($organization === null) {
            return redirect()
                ->route('organizations.index')
                ->with('error', 'Für API-Tokens braucht es zuerst eine Organisation.');
        }

        Gate::authorize('viewAny', [ApiToken::class, $organization]);

        return Inertia::render('api-tokens/Index', [
            ...ApiTokenData::index($organization, $user),
            // Einmalige Anzeige des gerade erzeugten Werts.
            'createdToken' => $request->session()->get('createdToken'),
        ]);
    }

    public function store(StoreApiTokenRequest $request): RedirectResponse
    {
        $user = $request->user();
        $organization = $request->organization();

        Gate::authorize('create', [ApiToken::class, $organization]);

        // Träger des Tokens: das Konto bei einem persönlichen, die Organisation
        // selbst bei einem organisationsweiten. Daran hängt, was beim Ausscheiden
        // des Ausstellers passiert.
        $tokenable = $request->kind() === ApiTokenKind::Organization
            ? $organization
            : $user;

        $token = ApiToken::issue(
            tokenable: $tokenable,
            organization: $organization,
            createdBy: $user,
            name: (string) $request->validated('name'),
            scopes: $request->scopes(),
            expiresAt: $request->expiresAt(),
        );

        return redirect()
            ->route('api-tokens.index')
            ->with('status', "Token „{$token->accessToken->name}“ angelegt.")
            ->with('createdToken', [
                'name' => $token->accessToken->name,
                'value' => $token->plainTextToken,
            ]);
    }

    public function destroy(Request $request, ApiToken $apiToken): RedirectResponse
    {
        Gate::authorize('delete', $apiToken);

        $name = $apiToken->name;
        $apiToken->delete();

        return redirect()
            ->route('api-tokens.index')
            ->with('status', "Token „{$name}“ widerrufen.");
    }
}
