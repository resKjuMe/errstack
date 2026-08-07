<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectPrivacyRequest;
use App\Models\Organization;
use App\Models\Project;
use App\Support\Ingest\Scrubbing\Scrubber;
use App\Support\Ingest\Scrubbing\Settings;
use App\Support\PrivacyData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Datenschutz eines Projekts: die drei Schalter, die eigenen Regeln und die
 * Vorschau.
 *
 * Ansehen darf jedes Mitglied — was von einer Meldung gespeichert wird, geht
 * jeden an, der mit den Daten arbeitet. Ändern darf es die Verwaltung
 * (ProjectPolicy `update`), wie bei den übrigen Projekteinstellungen: die
 * Schalter verändern, was von künftigen Meldungen erhalten bleibt, und das ist
 * dieselbe Art von Entscheidung wie die Aufbewahrungsdauer.
 */
class ProjectPrivacyController extends Controller
{
    public function index(Request $request, Organization $organization, Project $project): InertiaResponse
    {
        Gate::authorize('view', $project);

        return Inertia::render('privacy/Index', PrivacyData::forProject($project, $request->user()));
    }

    public function update(ProjectPrivacyRequest $request, Organization $organization, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);

        $project->update($request->validated());

        return back()->with('status', __('privacy.flash.options_updated'));
    }

    /**
     * Zeigt an einem Beispielereignis, was die geltenden Regeln entfernen würden.
     *
     * Gerechnet wird mit **denselben** Einstellungen wie in der Aufnahme
     * ({@see Settings::forProject()}) — eine Vorschau, die ihre eigene Liste
     * zusammenstellt, wäre eine Vermutung darüber, was passieren wird.
     *
     * Das Ergebnis kommt als Flash-Wert zurück und nicht als eigene Antwort: die
     * Seite ist eine Inertia-Seite, und ein zweiter Weg für dieses eine Ergebnis
     * hieße, den Zustand der Seite an zwei Stellen zu führen.
     */
    public function preview(Request $request, Organization $organization, Project $project): RedirectResponse
    {
        Gate::authorize('view', $project);

        $validated = $request->validate([
            'sample' => ['required', 'string', 'max:65535', 'json'],
        ]);

        /** @var array<mixed>|null $data */
        $data = json_decode((string) $validated['sample'], true);

        if (! is_array($data)) {
            // `json` lässt auch eine nackte Zahl durch — die ist gültiges JSON,
            // aber keine Meldung, und es gäbe nichts zu zeigen.
            return back()->withErrors(['sample' => __('privacy.validation.sample')]);
        }

        $result = (new Scrubber(Settings::forProject($project)))->scrub($data);

        return back()->with('scrubPreview', [
            'paths' => $result->paths,
            // Die Liste der Wege ist gekappt. Das gehört dazugesagt: eine Liste,
            // die vollständig aussieht, aber nicht vollständig ist, liest sich wie
            // eine Zusage — „mehr wurde nicht angefasst".
            'truncated' => count($result->paths) >= Scrubber::MAX_PATHS,
            'event' => json_encode($result->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }
}
