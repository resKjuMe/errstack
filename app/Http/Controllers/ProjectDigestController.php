<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectDigestRequest;
use App\Models\NotificationDigestEntry;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Die Bündelung von Benachrichtigungen je Projekt (A6).
 *
 * Ansehen darf sie jedes Mitglied — sie beantwortet die Frage, warum eine
 * Meldung erst mit Verzögerung kam, und die stellt sich nicht die Verwaltung,
 * sondern der, der auf sie gewartet hat. Verstellt wird sie von der Verwaltung:
 * das Fenster hochzusetzen heißt, alle Meldungen dieses Projekts später
 * zuzustellen.
 */
class ProjectDigestController extends Controller
{
    public function index(Organization $organization, Project $project): InertiaResponse
    {
        Gate::authorize('view', $project);

        return Inertia::render('projects/Digest', [
            'project' => [
                'name' => $project->name,
                'href' => route('projects.show', [$organization, $project]),
                'windowMinutes' => $project->digest_window_minutes,
                'minEvents' => $project->digest_min_events,
                'maxEvents' => $project->digest_max_events,
            ],
            'organization' => [
                'name' => $organization->name,
                'href' => route('organizations.show', $organization),
            ],
            // Was gerade wartet. Ohne diese Zahl ist die Seite eine
            // Einstellung ohne Rückmeldung: dass die Bündelung greift, sieht
            // man sonst frühestens an der nächsten Mail.
            'waiting' => NotificationDigestEntry::query()->where('project_id', $project->id)->count(),
            'canManage' => Gate::allows('update', $project),
            'hrefs' => [
                'update' => route('projects.digest.update', [$organization, $project]),
                'preferences' => route('notifications.preferences'),
            ],
        ]);
    }

    public function update(
        ProjectDigestRequest $request,
        Organization $organization,
        Project $project,
    ): RedirectResponse {
        Gate::authorize('update', $project);

        $project->update($request->validated());

        return back()->with('status', __('digests.flash.settings_saved'));
    }
}
