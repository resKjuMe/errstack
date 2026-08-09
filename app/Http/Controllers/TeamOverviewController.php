<?php

namespace App\Http\Controllers;

use App\Http\Requests\GlobalFilterRequest;
use App\Models\Organization;
use App\Models\Team;
use App\Support\Overviews\TeamOverview;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Die Übersichtsseite eines Teams.
 *
 * **Sie liegt bei den Fachseiten und nicht in den Einstellungen.** Verwaltet
 * wird ein Team dort (Mitglieder, Projekte); hier steht, was auf das Team
 * wartet. Das ist derselbe Schnitt wie zwischen Projekt-Einstellungen und
 * Projekt-Übersicht.
 */
class TeamOverviewController extends Controller
{
    public function __construct(private readonly TeamOverview $overview = new TeamOverview) {}

    public function index(GlobalFilterRequest $request, Organization $organization, Team $team): InertiaResponse
    {
        Gate::authorize('view', $team);

        return Inertia::render('teams/Overview', TeamOverview::frame($team, $request->filter()));
    }

    public function panel(
        GlobalFilterRequest $request,
        Organization $organization,
        Team $team,
        string $panel,
    ): JsonResponse {
        Gate::authorize('view', $team);

        return response()->json([
            'panel' => $this->overview->panel($panel, $team, $request->filter()),
        ]);
    }
}
