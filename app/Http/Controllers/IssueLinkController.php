<?php

namespace App\Http\Controllers;

use App\Models\Integration;
use App\Models\Issue;
use App\Models\IssueLink;
use App\Models\Organization;
use App\Models\Repository;
use App\Support\Integrations\GitHub\GitHubException;
use App\Support\Integrations\GitHub\GitHubIssueLinks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Aus einem Fehler ein Ticket machen — oder ihn an ein vorhandenes hängen (X1).
 *
 * Eine Adresse für beides, unterschieden durch die Angabe einer Nummer: mit
 * Nummer wird verknüpft, ohne wird angelegt. Zwei Adressen wären die
 * ordentlichere Aufteilung und die schlechtere Oberfläche — es ist **ein**
 * Formular mit einem Feld, das man ausfüllen kann oder nicht.
 *
 * Die Rechteprüfung hängt am Fehler und nicht an der Organisation: wer einen
 * Fehler bearbeiten darf (zuweisen, erledigen), darf ihn auch verknüpfen. Ein
 * Ticket anzulegen ist die kleinere Handlung — es ändert nichts an den Daten
 * hier und ist drüben in einem Repository sichtbar, das die Verwaltung
 * ausgewählt hat.
 */
class IssueLinkController extends Controller
{
    public function store(Request $request, Organization $organization, Issue $issue): RedirectResponse
    {
        Gate::authorize('update', $issue);

        $integration = Integration::forOrganization($organization);

        if ($integration === null || ! $integration->isUsable()) {
            return back()->with('status', __('integrations.errors.not_connected'));
        }

        $validated = $request->validate([
            // Nur ein Repository, das diese Organisation verbunden hat. Ein
            // freies Textfeld hieße, dass jedes Mitglied mit dem Token der
            // Organisation in jedem erreichbaren Repository Tickets anlegen
            // kann — auch in solchen, die mit dieser Anwendung nichts zu tun
            // haben.
            'repository' => [
                'required',
                'string',
                Rule::exists('repositories', 'name')
                    ->where('organization_id', $organization->id)
                    ->whereNotNull('integration_id'),
            ],
            // Mit Nummer verknüpfen, ohne anlegen.
            'number' => ['nullable', 'integer', 'min:1'],
        ], [], [
            'repository' => __('repositories.fields.name'),
            'number' => __('integrations.issue.fields.number'),
        ]);

        $repository = Repository::normalizeName($validated['repository']) ?? '';
        $number = $validated['number'] ?? null;

        try {
            $link = $number === null
                ? GitHubIssueLinks::create($issue, $integration, $repository, $request->user())
                : GitHubIssueLinks::link($issue, $integration, $repository, (int) $number, $request->user());
        } catch (GitHubException $e) {
            // Als Prüffehler am Formular und nicht als Fehlerseite: „es gibt
            // kein Ticket 4711" ist eine Eingabe, die man korrigiert, und der
            // Fehler gehört an das Feld, in dem sie steht.
            throw ValidationException::withMessages([
                ($number === null ? 'repository' : 'number') => $e->getMessage(),
            ]);
        }

        return back()->with('status', __('integrations.flash.issue_linked', [
            'reference' => $link->reference(),
        ]));
    }

    /**
     * Die Verknüpfung lösen. Das Ticket drüben bleibt stehen und offen — siehe
     * {@see GitHubIssueLinks::unlink()}.
     */
    public function destroy(Request $request, Organization $organization, Issue $issue, IssueLink $link): RedirectResponse
    {
        Gate::authorize('update', $issue);

        // Die Kennung des Fehlers steht auch im Pfad der Verknüpfung, und sie
        // ist dort keine Verzierung: ohne diese Prüfung löst eine vertauschte
        // Adresszeile eine fremde Verknüpfung unter fremdem Fehler.
        abort_unless($link->issue_id === $issue->id, 404);

        GitHubIssueLinks::unlink($link, $request->user());

        return back()->with('status', __('integrations.flash.issue_unlinked'));
    }
}
