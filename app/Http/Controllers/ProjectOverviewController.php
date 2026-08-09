<?php

namespace App\Http\Controllers;

use App\Http\Requests\GlobalFilterRequest;
use App\Models\Organization;
use App\Models\Project;
use App\Support\Overviews\ProjectOverview;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Die Übersichtsseite eines Projekts.
 *
 * **Ansehen darf jedes Mitglied, das das Projekt sehen darf** — dieselbe
 * Prüfung wie auf der Alarm-Übersicht und aus demselben Grund: hier wird
 * nachgesehen und nicht eingerichtet.
 *
 * Rahmen und Kacheln wie bei der Organisations-Übersicht: die Seite liefert das
 * Raster, jede Kachel holt ihre Zahlen selbst.
 */
class ProjectOverviewController extends Controller
{
    public function __construct(private readonly ProjectOverview $overview = new ProjectOverview) {}

    public function index(GlobalFilterRequest $request, Organization $organization, Project $project): InertiaResponse
    {
        Gate::authorize('view', $project);

        return Inertia::render('projects/Overview', ProjectOverview::frame($project, $request->filter()));
    }

    public function panel(
        GlobalFilterRequest $request,
        Organization $organization,
        Project $project,
        string $panel,
    ): JsonResponse {
        Gate::authorize('view', $project);

        return response()->json([
            'panel' => $this->overview->panel($panel, $project, $request->filter()),
        ]);
    }
}
