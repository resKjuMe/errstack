<?php

namespace App\Http\Controllers;

use App\Http\Requests\ScrubRuleRequest;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ScrubRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Eigene Datenschutz-Regeln anlegen, ändern und löschen — auf beiden Ebenen.
 *
 * Angelegt wird unterhalb der Stelle, zu der die Regel gehört: eine
 * organisationsweite an der Organisation, eine eigene am Projekt. Geändert und
 * gelöscht wird dagegen über eine Route ohne diesen Vorbau
 * (`datenschutz-regeln/{regel}`), wie bei Einladungen und Mitgliedschaften: die
 * Regel weiß selbst, wohin sie gehört, und der Weg dorthin noch einmal in der
 * Adresse wäre eine zweite Angabe derselben Sache — mit der Möglichkeit, dass
 * beide nicht zusammenpassen.
 */
class ScrubRuleController extends Controller
{
    /**
     * Eine Regel für alle Projekte der Organisation.
     */
    public function store(ScrubRuleRequest $request, Organization $organization): RedirectResponse
    {
        Gate::authorize('manageProjects', $organization);

        return self::create($request, $organization, null);
    }

    /**
     * Eine Regel nur für dieses Projekt.
     */
    public function storeForProject(ScrubRuleRequest $request, Organization $organization, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);

        return self::create($request, $organization, $project);
    }

    public function update(ScrubRuleRequest $request, ScrubRule $scrubRule): RedirectResponse
    {
        self::authorizeFor($scrubRule);

        $scrubRule->update($request->validated());

        return back()->with('status', __('privacy.flash.rule_updated'));
    }

    public function destroy(ScrubRule $scrubRule): RedirectResponse
    {
        self::authorizeFor($scrubRule);

        $scrubRule->delete();

        return back()->with('status', __('privacy.flash.rule_deleted'));
    }

    private static function create(ScrubRuleRequest $request, Organization $organization, ?Project $project): RedirectResponse
    {
        $rule = new ScrubRule($request->validated());

        $rule->organization_id = $organization->id;
        $rule->project_id = $project?->id;
        $rule->save();

        return back()->with('status', __('privacy.flash.rule_created'));
    }

    /**
     * Wer darf diese Regel ändern?
     *
     * Am Projekt dasselbe Recht wie für seine übrigen Einstellungen, an der
     * Organisation das Recht, Projekte zu verwalten — eine Regel dort wirkt auf
     * alle Projekte, und wer sie schreibt, bestimmt damit über alle.
     */
    private static function authorizeFor(ScrubRule $scrubRule): void
    {
        if ($scrubRule->isOrganizationWide()) {
            Gate::authorize('manageProjects', $scrubRule->organization);

            return;
        }

        Gate::authorize('update', $scrubRule->project);
    }
}
