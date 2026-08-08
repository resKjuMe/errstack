<?php

namespace App\Http\Controllers;

use App\Http\Requests\SamplingRuleRequest;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SamplingRule;
use App\Models\Transaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Die projektweiten Stichproben-Regeln: anlegen, ändern, verschieben, löschen.
 *
 * Ansehen darf jedes Mitglied — die Regeln erklären, warum in der
 * Performance-Übersicht mehr Aufrufe stehen, als Messungen gespeichert sind, und
 * diese Frage stellt sich nicht nur die Verwaltung. Ändern darf nur die
 * Verwaltung: eine Regel entscheidet, welche Messungen es künftig **nicht**
 * geben wird.
 *
 * **Rückwirkend ändert sich nichts.** Was bereits gespeichert ist, bleibt
 * gespeichert; was ausgesiebt wurde, kommt nicht zurück. Das ist der
 * wesentliche Unterschied zu den Fingerprint-Regeln, bei denen ein Fehler durch
 * eine erneute Auswertung zu heilen ist — hier sind die Daten weg. Eine niedrige
 * Quote ist deshalb eine Entscheidung, die man beim Einstellen treffen muss und
 * nicht später korrigieren kann.
 */
class SamplingRuleController extends Controller
{
    public function index(Organization $organization, Project $project): InertiaResponse
    {
        Gate::authorize('view', $project);

        return Inertia::render('projects/Sampling', [
            'organization' => [
                'name' => $organization->name,
                'href' => route('organizations.show', $organization),
            ],
            'project' => [
                'name' => $project->name,
                'href' => route('projects.show', [$organization, $project]),
                'samplingHref' => route('projects.sampling.store', [$organization, $project]),
            ],
            'rules' => $this->rules($organization, $project),
            'conditions' => SamplingRule::CONDITIONS,
            // Die Fensterbreite gehört in die Anzeige, weil die Mindestquote
            // ohne sie nicht zu deuten ist: „mindestens 2" heißt „je Minute" und
            // nicht „je Stunde".
            'windowSeconds' => Transaction::BUCKET_SECONDS,
            'canManage' => Gate::allows('manageSampling', $project),
        ]);
    }

    public function store(SamplingRuleRequest $request, Organization $organization, Project $project): RedirectResponse
    {
        Gate::authorize('manageSampling', $project);

        if ($project->samplingRules()->count() >= SamplingRule::MAX_PER_PROJECT) {
            return back()->withErrors([
                'name' => __('sampling.validation.too_many', ['max' => SamplingRule::MAX_PER_PROJECT]),
            ]);
        }

        $rule = new SamplingRule($request->validated());

        // Neue Regeln kommen ans Ende. Vorne einzureihen wäre hier die
        // gefährlichere Wahl als beim Grouping: die erste zutreffende Regel
        // gewinnt, und eine frisch angelegte Regel ohne Bedingung würde damit
        // stillschweigend die Quote **aller** bestehenden übernehmen.
        $rule->position = $request->has('position')
            ? (int) $request->validated('position')
            : (int) $project->samplingRules()->max('position') + 1;

        $project->samplingRules()->save($rule);

        return back()->with('status', __('sampling.flash.created', ['name' => $rule->name]));
    }

    public function update(
        SamplingRuleRequest $request,
        Organization $organization,
        Project $project,
        SamplingRule $samplingRule,
    ): RedirectResponse {
        Gate::authorize('manageSampling', $project);

        $samplingRule->fill($request->validated())->save();

        return back()->with('status', __('sampling.flash.updated', ['name' => $samplingRule->name]));
    }

    /**
     * An- und abschalten.
     *
     * Eine abgeschaltete Regel bleibt samt Bedingungen stehen, greift aber nicht
     * mehr — der Weg, eine Quote probeweise auszusetzen, ohne sie neu schreiben
     * zu müssen. Solange sie aus ist, wird alles behalten, worauf sie zutraf.
     */
    public function toggle(Organization $organization, Project $project, SamplingRule $samplingRule): RedirectResponse
    {
        Gate::authorize('manageSampling', $project);

        $samplingRule->is_active = ! $samplingRule->is_active;
        $samplingRule->save();

        return back()->with('status', __(
            $samplingRule->is_active ? 'sampling.flash.enabled' : 'sampling.flash.disabled',
            ['name' => $samplingRule->name],
        ));
    }

    public function destroy(Organization $organization, Project $project, SamplingRule $samplingRule): RedirectResponse
    {
        Gate::authorize('manageSampling', $project);

        $name = $samplingRule->name;

        $samplingRule->delete();

        return back()->with('status', __('sampling.flash.deleted', ['name' => $name]));
    }

    /**
     * Die Regeln für die Anzeige.
     *
     * @return list<array<string, mixed>>
     */
    private function rules(Organization $organization, Project $project): array
    {
        return $project->samplingRules()
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (SamplingRule $rule): array => [
                'id' => $rule->id,
                'name' => $rule->name,
                'transaction_name' => $rule->transaction_name,
                'environment' => $rule->environment,
                'release' => $rule->release,
                'op' => $rule->op,
                'sample_rate' => $rule->sample_rate,
                'minimum_per_window' => $rule->minimum_per_window,
                'position' => $rule->position,
                'active' => $rule->is_active,
                'href' => route('projects.sampling.update', [$organization, $project, $rule]),
                'toggleHref' => route('projects.sampling.toggle', [$organization, $project, $rule]),
            ])
            ->values()
            ->all();
    }
}
