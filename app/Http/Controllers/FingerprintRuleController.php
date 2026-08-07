<?php

namespace App\Http\Controllers;

use App\Http\Requests\FingerprintRuleRequest;
use App\Models\FingerprintRule;
use App\Models\Organization;
use App\Models\Project;
use App\Support\Ingest\Grouping\Matcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Die projektweiten Fingerprint-Regeln: anlegen, ändern, verschieben, löschen.
 *
 * Ansehen darf jedes Mitglied — die Regeln erklären, warum die Fehlerliste so
 * aussieht, wie sie aussieht, und diese Frage stellt sich nicht nur die
 * Verwaltung. Ändern darf nur die Verwaltung (ProjectPolicy): eine Regel wirkt
 * auf **alle** künftigen Meldungen des Projekts.
 *
 * **Rückwirkend ändert sich nichts.** Meldungen, die bereits ausgewertet sind,
 * behalten ihre Gruppe und ihre Begründung. Das ist Absicht und keine Lücke:
 * eine Regel, die alte Meldungen umsortiert, würde Zähler, Zeitverläufe und
 * bereits verschickte Alarme im Nachhinein verfälschen. Wer den Bestand
 * mitziehen will, wertet erneut aus — eine eigene Handlung mit eigener
 * Entscheidung.
 */
class FingerprintRuleController extends Controller
{
    public function index(Organization $organization, Project $project): InertiaResponse
    {
        Gate::authorize('view', $project);

        return Inertia::render('projects/Grouping', [
            'organization' => [
                'name' => $organization->name,
                'href' => route('organizations.show', $organization),
            ],
            'project' => [
                'name' => $project->name,
                'href' => route('projects.show', [$organization, $project]),
                'groupingHref' => route('projects.grouping.store', [$organization, $project]),
            ],
            'rules' => $this->rules($organization, $project),
            'attributes' => FingerprintRuleRequest::ATTRIBUTES,
            'canManage' => Gate::allows('manageGrouping', $project),
        ]);
    }

    public function store(FingerprintRuleRequest $request, Organization $organization, Project $project): RedirectResponse
    {
        Gate::authorize('manageGrouping', $project);

        if ($project->fingerprintRules()->count() >= FingerprintRule::MAX_PER_PROJECT) {
            return back()->withErrors([
                'name' => __('grouping.validation.too_many', ['max' => FingerprintRule::MAX_PER_PROJECT]),
            ]);
        }

        $rule = new FingerprintRule($request->validated());

        // Neue Regeln kommen ans Ende. Vorne einzureihen wäre die gefährlichere
        // Wahl: die erste zutreffende Regel gewinnt, und eine frisch angelegte
        // würde damit stillschweigend alle bestehenden überstimmen.
        $rule->position = $request->has('position')
            ? (int) $request->validated('position')
            : (int) $project->fingerprintRules()->max('position') + 1;

        $project->fingerprintRules()->save($rule);

        return back()->with('status', __('grouping.flash.created', ['name' => $rule->name]));
    }

    public function update(
        FingerprintRuleRequest $request,
        Organization $organization,
        Project $project,
        FingerprintRule $fingerprintRule,
    ): RedirectResponse {
        Gate::authorize('manageGrouping', $project);

        $fingerprintRule->fill($request->validated())->save();

        return back()->with('status', __('grouping.flash.updated', ['name' => $fingerprintRule->name]));
    }

    /**
     * An- und abschalten.
     *
     * Eine abgeschaltete Regel bleibt samt Bedingungen stehen, greift aber
     * nicht mehr — der Weg, eine Regel probeweise stillzulegen, ohne sie neu
     * schreiben zu müssen.
     */
    public function toggle(Organization $organization, Project $project, FingerprintRule $fingerprintRule): RedirectResponse
    {
        Gate::authorize('manageGrouping', $project);

        $fingerprintRule->is_active = ! $fingerprintRule->is_active;
        $fingerprintRule->save();

        return back()->with('status', __(
            $fingerprintRule->is_active ? 'grouping.flash.enabled' : 'grouping.flash.disabled',
            ['name' => $fingerprintRule->name],
        ));
    }

    public function destroy(Organization $organization, Project $project, FingerprintRule $fingerprintRule): RedirectResponse
    {
        Gate::authorize('manageGrouping', $project);

        $name = $fingerprintRule->name;

        $fingerprintRule->delete();

        return back()->with('status', __('grouping.flash.deleted', ['name' => $name]));
    }

    /**
     * Die Regeln für die Anzeige.
     *
     * @return list<array<string, mixed>>
     */
    private function rules(Organization $organization, Project $project): array
    {
        return $project->fingerprintRules()
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (FingerprintRule $rule): array => [
                'id' => $rule->id,
                'name' => $rule->name,
                // Über die geprüften Bedingungen und nicht über die rohen
                // Werte: was die Gruppierung übergeht, soll auch die Anzeige
                // übergehen — sonst steht in der Liste eine Bedingung, die
                // nichts tut.
                'matchers' => array_map(
                    static fn (Matcher $matcher): array => $matcher->toArray(),
                    $rule->conditions(),
                ),
                'fingerprint' => $rule->values(),
                'position' => $rule->position,
                'active' => $rule->is_active,
                'href' => route('projects.grouping.update', [$organization, $project, $rule]),
                'toggleHref' => route('projects.grouping.toggle', [$organization, $project, $rule]),
            ])
            ->values()
            ->all();
    }
}
