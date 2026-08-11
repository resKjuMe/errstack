<?php

namespace App\Http\Controllers;

use App\Enums\IntegrationProvider;
use App\Models\Integration;
use App\Models\Organization;
use App\Support\Integrations\Tickets\TicketException;
use App\Support\Integrations\Tickets\TicketProviders;
use App\Support\Integrations\Tickets\TicketTarget;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Wohin ein Ticket gelegt werden kann — Jira-Projekte, Linear-Teams (X4).
 *
 * **Eine eigene Adresse und keine Angabe an der Seite**, und das ist dieselbe
 * Entscheidung wie bei der Repository-Auswahl (X1): die Liste ist ein Aufruf
 * über das Netz, und weder die Fehlerseite noch die Einstellungsseite sollen
 * sich deshalb um eine Netzwerkrunde verzögern — oder gar nicht laden, wenn Jira
 * gerade nicht antwortet. Geholt wird sie, wenn jemand sie öffnet.
 *
 * **Ein Fehlschlag ist hier `200` mit einer Meldung und kein Fehlerstatus.** Der
 * Aufrufer ist ein Formular im Browser, das die Auswahl anzeigen oder erklären
 * will, warum sie leer ist; ein `502` würde dort als „kaputt" erscheinen, obwohl
 * die Antwort „das Token ist abgelaufen" lautet. Dieselbe Form wie bei der
 * Repository-Auswahl, damit die Oberfläche beide gleich behandeln kann.
 */
class TicketTargetController extends Controller
{
    public function index(Organization $organization, string $provider): JsonResponse
    {
        // Ansehen darf, wer die Organisation sehen darf: die Liste ist die
        // Voraussetzung dafür, aus einem Fehler ein Ticket zu machen, und das
        // darf jedes Mitglied, das den Fehler bearbeiten darf. Sie hinter
        // `manageIntegrations` zu legen hieße, das Anlegen der Verwaltung zu
        // überlassen.
        Gate::authorize('view', $organization);

        $case = IntegrationProvider::tryFrom($provider);

        abort_if($case === null || ! $case->isTicketProvider(), 404);

        $integration = Integration::forOrganization($organization, $case);
        $tickets = TicketProviders::for($integration);

        if ($tickets === null) {
            return response()->json([
                'targets' => [],
                'error' => __('integrations.errors.ticket_not_connected', ['provider' => $case->label()]),
            ]);
        }

        try {
            $targets = $tickets->targets();
        } catch (TicketException $e) {
            return response()->json(['targets' => [], 'error' => $e->getMessage()]);
        }

        return response()->json([
            'targets' => array_map(fn (TicketTarget $target): array => $target->toArray(), $targets),
            'error' => null,
        ]);
    }
}
