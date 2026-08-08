<?php

namespace App\Http\Controllers;

use App\Enums\InboundFilterKind;
use App\Http\Requests\InboundFilterRuleRequest;
use App\Http\Requests\ProjectFilterRequest;
use App\Models\InboundFilterRule;
use App\Models\Organization;
use App\Models\Project;
use App\Support\InboundFilterData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Die Eingangsfilter eines Projekts: die sieben Schalter, die Listen und die
 * Zählung dessen, was sie weggenommen haben.
 *
 * Ansehen darf jedes Mitglied, und das ist hier keine Höflichkeit gegenüber der
 * Belegschaft: wer eine Meldung vermisst, muss nachsehen können, ob sie
 * gefiltert wurde. Ohne diese Seite wäre die Antwort auf „warum ist mein Fehler
 * nicht da?" nur der Verwaltung zugänglich — und damit für den, der den Fehler
 * gemeldet hat, gar nicht.
 *
 * Ändern darf die Verwaltung (ProjectPolicy `manageFilters`). Ein Filter
 * entscheidet, ob Meldungen überhaupt entstehen; das ist dieselbe Art von
 * Entscheidung wie die Aufbewahrungsdauer und keine, die nebenbei getroffen
 * wird.
 */
class ProjectFilterController extends Controller
{
    public function index(Request $request, Organization $organization, Project $project): InertiaResponse
    {
        Gate::authorize('view', $project);

        return Inertia::render('projects/Filters', InboundFilterData::forProject($project, $request->user()));
    }

    public function update(ProjectFilterRequest $request, Organization $organization, Project $project): RedirectResponse
    {
        Gate::authorize('manageFilters', $project);

        $project->update($request->validated());

        return back()->with('status', __('inbound.flash.options_updated'));
    }

    public function store(InboundFilterRuleRequest $request, Organization $organization, Project $project): RedirectResponse
    {
        Gate::authorize('manageFilters', $project);

        /** @var InboundFilterKind $kind */
        $kind = InboundFilterKind::from((string) $request->validated('kind'));

        $existing = $project->inboundFilterRules()->where('kind', $kind->value)->count();

        if ($existing >= InboundFilterRule::MAX_PER_KIND) {
            return back()->withErrors([
                'expression' => __('inbound.validation.too_many', ['max' => InboundFilterRule::MAX_PER_KIND]),
            ]);
        }

        $rule = new InboundFilterRule($request->validated());

        $project->inboundFilterRules()->save($rule);

        return back()->with('status', __('inbound.flash.rule_created', ['expression' => $rule->expression]));
    }

    public function updateRule(
        InboundFilterRuleRequest $request,
        Organization $organization,
        Project $project,
        InboundFilterRule $inboundFilterRule,
    ): RedirectResponse {
        Gate::authorize('manageFilters', $project);

        $inboundFilterRule->fill($request->validated())->save();

        return back()->with('status', __('inbound.flash.rule_updated', [
            'expression' => $inboundFilterRule->expression,
        ]));
    }

    /**
     * Einen einzelnen Eintrag still legen, ohne ihn zu löschen.
     *
     * Der Weg, ein Muster zu prüfen, das im Verdacht steht, zu viel wegzunehmen:
     * abschalten, ein paar Stunden zusehen, ob die vermisste Meldung wieder
     * ankommt — und erst dann löschen oder wieder einschalten.
     */
    public function toggle(
        Organization $organization,
        Project $project,
        InboundFilterRule $inboundFilterRule,
    ): RedirectResponse {
        Gate::authorize('manageFilters', $project);

        $inboundFilterRule->is_active = ! $inboundFilterRule->is_active;
        $inboundFilterRule->save();

        return back()->with('status', __(
            $inboundFilterRule->is_active ? 'inbound.flash.rule_enabled' : 'inbound.flash.rule_disabled',
            ['expression' => $inboundFilterRule->expression],
        ));
    }

    public function destroy(
        Organization $organization,
        Project $project,
        InboundFilterRule $inboundFilterRule,
    ): RedirectResponse {
        Gate::authorize('manageFilters', $project);

        $expression = $inboundFilterRule->expression;

        $inboundFilterRule->delete();

        return back()->with('status', __('inbound.flash.rule_deleted', ['expression' => $expression]));
    }
}
