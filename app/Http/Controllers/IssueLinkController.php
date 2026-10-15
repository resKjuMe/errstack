<?php

namespace App\Http\Controllers;

use App\Enums\IntegrationProvider;
use App\Models\Integration;
use App\Models\Issue;
use App\Models\IssueLink;
use App\Models\Organization;
use App\Models\Repository;
use App\Support\Integrations\GitHub\GitHubException;
use App\Support\Integrations\GitHub\GitHubIssueLinks;
use App\Support\Integrations\Tickets\TicketException;
use App\Support\Integrations\Tickets\TicketLinks;
use App\Support\Integrations\Tickets\TicketProvider;
use App\Support\Integrations\Tickets\TicketProviders;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Aus einem Fehler ein Ticket machen — oder ihn an ein vorhandenes hängen
 * (X1, X4).
 *
 * Eine Adresse für beides, unterschieden durch die Angabe einer Nummer: mit
 * Nummer wird verknüpft, ohne wird angelegt. Zwei Adressen wären die
 * ordentlichere Aufteilung und die schlechtere Oberfläche — es ist **ein**
 * Formular mit einem Feld, das man ausfüllen kann oder nicht.
 *
 * **Und eine Adresse für alle Anbieter** (X4). Welcher gemeint ist, sagt das Feld
 * `provider`; fehlt es, ist GitHub gemeint. Der Standard ist kein Zugeständnis an
 * die Bequemlichkeit, sondern an die Verträglichkeit: die Adresse gab es vor X4,
 * und ein Formular, das sie ohne Anbieter aufruft, soll weiterhin dasselbe tun.
 *
 * Die Rechteprüfung hängt am Fehler und nicht an der Organisation: wer einen
 * Fehler bearbeiten darf (zuweisen, erledigen), darf ihn auch verknüpfen. Ein
 * Ticket anzulegen ist die kleinere Handlung — es ändert nichts an den Daten hier
 * und ist drüben in einem Projekt sichtbar, das die Verwaltung ausgewählt hat.
 */
class IssueLinkController extends Controller
{
    public function store(Request $request, Organization $organization, Issue $issue): RedirectResponse
    {
        Gate::authorize('update', $issue);

        $provider = self::provider($request);
        $integration = Integration::forOrganization($organization, $provider);

        if ($integration === null || ! $integration->isUsable()) {
            return back()->with('status', __('integrations.errors.ticket_not_connected', [
                'provider' => $provider->label(),
            ]));
        }

        return $provider->isTicketProvider()
            ? $this->ticket($request, $issue, $integration)
            : $this->github($request, $organization, $issue, $integration);
    }

    /**
     * Die Verknüpfung lösen. Das Ticket drüben bleibt stehen und offen — siehe
     * {@see TicketLinks::unlink()}.
     *
     * **Ein Weg für alle Anbieter**, weil das Lösen keinen von ihnen fragt: es
     * löscht eine Zeile hier und schreibt einen Vermerk. Die beiden Klassen tun
     * genau dasselbe; welche es ist, entscheidet trotzdem der Anbieter — der
     * Vermerk im Verlauf trägt die Kennung in seiner Schreibweise, und die kommt
     * aus der Verknüpfung.
     */
    public function destroy(Request $request, Organization $organization, Issue $issue, IssueLink $link): RedirectResponse
    {
        Gate::authorize('update', $issue);

        // Die Kennung des Fehlers steht auch im Pfad der Verknüpfung, und sie
        // ist dort keine Verzierung: ohne diese Prüfung löst eine vertauschte
        // Adresszeile eine fremde Verknüpfung unter fremdem Fehler.
        abort_unless($link->issue_id === $issue->id, 404);

        $link->provider->isTicketProvider()
            ? TicketLinks::unlink($link, $request->user())
            : GitHubIssueLinks::unlink($link, $request->user());

        return back()->with('status', __('integrations.flash.issue_unlinked'));
    }

    /**
     * Ein Ticket bei Jira oder Linear.
     */
    private function ticket(Request $request, Issue $issue, Integration $integration): RedirectResponse
    {
        $tickets = TicketProviders::for($integration);

        if (! $tickets instanceof TicketProvider) {
            return back()->with('status', __('integrations.errors.ticket_not_connected', [
                'provider' => $integration->provider->label(),
            ]));
        }

        $validated = $request->validate([
            // Freitext und keine Auswahl aus der Datenbank — anders als bei
            // GitHub, wo die Repositories der Organisation als Zeilen vorliegen.
            // Die Projekte eines Ticket-Systems liegen hier nicht; sie werden auf
            // Anforderung geholt (siehe TicketTargetController), und eine
            // Prüfung dagegen wäre eine Netzwerkrunde beim Absenden des
            // Formulars. Ein Schlüssel, den es nicht gibt, meldet sich mit der
            // Meldung des Anbieters — und die ist aussagekräftiger.
            'repository' => ['required', 'string', 'max:100'],
            // Als Zeichenkette, weil hier auch `OPS-42` ankommen darf: das ist
            // die Form, in der die Kennung drüben steht und aus der Zwischenablage
            // kommt. Wer sie abtippen soll, tippt sie ganz.
            'number' => ['nullable', 'string', 'max:50'],
        ], [], [
            'repository' => __('integrations.ticket.fields.target'),
            'number' => __('integrations.issue.fields.number'),
        ]);

        $target = trim($validated['repository']);
        $number = self::number($validated['number'] ?? null, $target);

        if (($validated['number'] ?? '') !== '' && $number === null) {
            throw ValidationException::withMessages([
                'number' => __('integrations.errors.invalid_number'),
            ]);
        }

        try {
            $link = $number === null
                ? TicketLinks::create($issue, $integration, $tickets, $target, $request->user())
                : TicketLinks::link($issue, $integration, $tickets, $target, $number, $request->user());
        } catch (TicketException $e) {
            throw ValidationException::withMessages([
                ($number === null ? 'repository' : 'number') => $e->getMessage(),
            ]);
        }

        return back()->with('status', __('integrations.flash.issue_linked', [
            'reference' => $link->reference(),
        ]));
    }

    /**
     * Ein Ticket bei GitHub — unverändert der Weg aus X1.
     */
    private function github(Request $request, Organization $organization, Issue $issue, Integration $integration): RedirectResponse
    {
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
     * Die Nummer aus der Eingabe — `42` oder `OPS-42`.
     *
     * Der Schlüssel darf davorstehen und wird abgeschnitten, wenn er zum
     * ausgewählten Projekt passt. Passt er **nicht**, bleibt die Eingabe ungültig:
     * `ENG-42` im Projekt `OPS` ist keine Nummer mit Vorsilbe, sondern ein
     * anderes Ticket — und es stillschweigend als `OPS-42` zu lesen wäre die
     * Sorte Hilfsbereitschaft, die eine Verknüpfung auf den falschen Vorgang
     * legt.
     */
    private static function number(?string $input, string $target): ?int
    {
        $input = trim((string) $input);

        if ($input === '') {
            return null;
        }

        if (ctype_digit($input)) {
            return (int) $input > 0 ? (int) $input : null;
        }

        $prefix = $target.'-';

        if (stripos($input, $prefix) === 0) {
            $rest = substr($input, strlen($prefix));

            return ctype_digit($rest) && (int) $rest > 0 ? (int) $rest : null;
        }

        return null;
    }

    /**
     * Welcher Anbieter gemeint ist.
     *
     * Ohne Angabe GitHub: die Adresse gab es vor X4, und ein Aufruf ohne das Feld
     * soll weiterhin dasselbe tun. Ein unbekannter Wert ist ein `404` und keine
     * stille Rückkehr zu GitHub — sonst legt ein Tippfehler im Formular ein
     * Ticket im falschen System an.
     */
    private static function provider(Request $request): IntegrationProvider
    {
        $value = $request->string('provider')->toString();

        if ($value === '') {
            return IntegrationProvider::GitHub;
        }

        $provider = IntegrationProvider::tryFrom($value);

        abort_if($provider === null, 404);

        return $provider;
    }
}
