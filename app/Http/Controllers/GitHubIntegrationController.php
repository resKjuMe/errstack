<?php

namespace App\Http\Controllers;

use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Models\Integration;
use App\Models\Organization;
use App\Support\Integrations\GitHub\GitHubClient;
use App\Support\Integrations\GitHub\GitHubException;
use App\Support\Integrations\GitHub\GitHubOAuth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Der Weg herein: Anmeldung bei GitHub und Rückkehr mit dem Code.
 *
 * Zwei Adressen und ein Sitzungswert dazwischen — mehr braucht OAuth nicht, und
 * der Sitzungswert ist der Teil, der nicht wegzulassen ist: ohne ihn nimmt die
 * Rückkehr-Adresse jeden Code entgegen, den ihr jemand unterschiebt, und
 * verbindet die Organisation des Angemeldeten mit dem GitHub-Konto des
 * Angreifers.
 *
 * **Die Rückkehr trägt die Organisation nicht in der Adresse.** Sie steht im
 * `state`-Wert, und der liegt in der Sitzung. Das ist keine Umständlichkeit:
 * die Rückkehr-Adresse muss bei GitHub fest hinterlegt werden, und eine, die je
 * Organisation anders aussieht, ließe sich dort nicht eintragen.
 */
class GitHubIntegrationController extends Controller
{
    /**
     * Der Schlüssel, unter dem der `state`-Wert in der Sitzung liegt.
     */
    private const STATE_KEY = 'github_oauth_state';

    public function redirect(Request $request, Organization $organization): RedirectResponse
    {
        Gate::authorize('manageIntegrations', $organization);

        if (! GitHubOAuth::isConfigured()) {
            return back()->with('status', __('integrations.flash.not_configured'));
        }

        $state = GitHubOAuth::state($organization->slug);

        $request->session()->put(self::STATE_KEY, $state);

        return redirect()->away(GitHubOAuth::authorizeUrl($state, route('integrations.github.callback')));
    }

    /**
     * Die Rückkehr von GitHub.
     *
     * Der Ablauf hat vier Stellen, an denen abgebrochen wird, und alle vier
     * enden mit einer Meldung auf der Anbindungsseite statt mit einer
     * Fehlerseite: wer hier landet, hat gerade auf „Verbinden" geklickt, und
     * eine Fehlerseite ohne Rückweg ist die schlechteste Antwort darauf.
     */
    public function callback(Request $request): RedirectResponse
    {
        $expected = $request->session()->pull(self::STATE_KEY);
        $slug = GitHubOAuth::organizationFrom(
            is_string($expected) ? $expected : null,
            $request->string('state')->toString(),
        );

        if ($slug === null) {
            // Kein oder ein fremder `state`: entweder ist die Sitzung
            // abgelaufen, oder jemand hat die Adresse untergeschoben. Beide
            // Male gibt es nichts zu verbinden.
            return redirect()->route('organizations.index')
                ->with('status', __('integrations.flash.state_mismatch'));
        }

        $organization = Organization::query()->where('slug', $slug)->firstOrFail();

        Gate::authorize('manageIntegrations', $organization);

        $target = redirect()->route('organizations.integrations.index', $organization);

        // GitHub schickt bei einem Abbruch (`Cancel`) keinen Code, sondern
        // `error=access_denied`. Das ist kein Fehlschlag, sondern eine
        // Entscheidung — und wird auch so gemeldet.
        $code = $request->string('code')->toString();

        if ($code === '') {
            return $target->with('status', __('integrations.flash.aborted'));
        }

        try {
            $token = GitHubOAuth::exchange($code, route('integrations.github.callback'));

            $integration = self::store($organization, $token);

            $viewer = (new GitHubClient($integration))->viewer();

            $integration->forceFill([
                'account' => $viewer['login'],
                'external_id' => $viewer['id'],
            ])->save();
        } catch (GitHubException $e) {
            return $target->with('status', __('integrations.flash.failed', ['reason' => $e->getMessage()]));
        }

        return $target->with('status', __('integrations.flash.connected'));
    }

    /**
     * Die Anbindung anlegen — oder die vorhandene mit dem neuen Token
     * versehen.
     *
     * Der zweite Fall ist der wichtigere: er ist der Weg zurück aus
     * „Verbindung verloren". Eine neue Zeile anzulegen ginge nicht (ein Zugang
     * je Organisation und Anbieter), und die alte zu löschen nähme den
     * verbundenen Repositories ihre Zuordnung — sie würden zu von Hand
     * eingetragenen, und niemand holte mehr ihre Commits.
     */
    private static function store(Organization $organization, string $token): Integration
    {
        $integration = Integration::forOrganization($organization) ?? new Integration([
            'provider' => IntegrationProvider::GitHub,
        ]);

        $integration->organization_id = $organization->id;
        $integration->provider = IntegrationProvider::GitHub;

        // `forceFill` für die Zugangsdaten: sie stehen mit Absicht nicht in
        // `fillable` (siehe {@see Integration}), damit sie nie aus einer
        // Anfrage heraus gesetzt werden können.
        $integration->forceFill([
            'credentials' => ['token' => $token],
            'status' => IntegrationStatus::Connected,
            'last_error' => null,
            'last_error_at' => null,
            'connected_by_id' => Auth::id(),
        ])->save();

        return $integration;
    }
}
