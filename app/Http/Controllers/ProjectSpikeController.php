<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectSpikeRequest;
use App\Models\Organization;
use App\Models\Project;
use App\Support\Ingest\Spikes\SpikeGuard;
use App\Support\Ingest\Spikes\SpikeStatus;
use App\Support\SpikeData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Der Ausschlag-Schutz eines Projekts (A7): Einstellungen, laufender Zustand
 * und das Aufheben von Hand.
 *
 * Ansehen darf jedes Mitglied — die Seite beantwortet „warum fehlen mir
 * Meldungen?", und diese Frage stellt gerade der, der die Einstellung nicht
 * ändern darf. Verstellt und aufgehoben wird von der Verwaltung: beides
 * entscheidet darüber, was von den gemeldeten Ereignissen überhaupt gespeichert
 * wird.
 */
class ProjectSpikeController extends Controller
{
    public function index(Request $request, Organization $organization, Project $project): InertiaResponse
    {
        Gate::authorize('view', $project);

        return Inertia::render('projects/Spikes', SpikeData::forProject($project, $request->user()));
    }

    public function update(
        ProjectSpikeRequest $request,
        Organization $organization,
        Project $project,
    ): RedirectResponse {
        Gate::authorize('update', $project);

        $project->update($request->validated());

        // Der Zustand, aus dem die Aufnahme entscheidet, steht im
        // Zwischenspeicher. Ohne diesen Schritt liefe der Schutz nach dem
        // Abschalten noch bis zu einer Minute weiter — und wer gerade in einer
        // Flut steht und ihn abschaltet, wartet nicht gern eine Minute auf die
        // Wirkung seines Klicks.
        SpikeStatus::refresh($project);

        return back()->with('status', __('spikes.flash.saved'));
    }

    /**
     * Hebt die laufende Drosselung auf.
     *
     * Ein eigener Weg und nicht der Schalter: das Abschalten des Schutzes ist
     * eine Einstellung für die Zukunft, das Aufheben eine Entscheidung über
     * genau diesen Vorfall — „ich weiß, was da passiert, lass es durch". Wer
     * beides in einen Schalter legte, zwänge jeden, der einmal durchlassen
     * will, den Schutz dauerhaft abzuschalten.
     */
    public function release(
        Request $request,
        Organization $organization,
        Project $project,
        SpikeGuard $guard,
    ): RedirectResponse {
        Gate::authorize('update', $project);

        $released = $guard->release($project, $request->user());

        return back()->with('status', __($released ? 'spikes.flash.released' : 'spikes.flash.nothing_to_release'));
    }
}
