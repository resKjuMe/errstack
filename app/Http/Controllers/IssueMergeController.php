<?php

namespace App\Http\Controllers;

use App\Http\Requests\IssueMergeRequest;
use App\Models\Issue;
use App\Support\Issues\IssueMerging;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Fehler von Hand zusammenführen und wieder auftrennen.
 *
 * Die Korrektur an der automatischen Gruppierung (I5): sie liegt manchmal zu
 * grob oder zu fein, und dann ist die Antwort auf „wie schlimm ist das" auf zwei
 * Einträge verteilt oder in einen zusammengefallen. Beides lässt sich hier von
 * Hand richten — und beides ist umkehrbar, weshalb es kein Verwaltungsrecht
 * braucht, sondern die tägliche Arbeit an der Fehlerliste ist.
 *
 * Zusammengeführt wird aus der **Liste** heraus (dort stehen die Einträge, die
 * zusammengehören), aufgetrennt aus der **Detailseite** (dort steht, woraus ein
 * Eintrag besteht).
 */
class IssueMergeController extends Controller
{
    /**
     * Führt die gewählten Einträge zusammen.
     *
     * Das Ziel steht danach fest, nicht vorher: welcher Eintrag der Kopf wird,
     * bestimmt {@see IssueMerging::merge()}. Deshalb führt der Weg auf dessen
     * Detailseite — dorthin, wo das Ergebnis steht und wo es sich wieder
     * auftrennen lässt.
     */
    public function store(IssueMergeRequest $request): RedirectResponse
    {
        $issues = $request->issues();

        foreach ($issues as $issue) {
            Gate::authorize('merge', $issue);
        }

        $head = IssueMerging::merge($issues);

        return redirect()
            ->route('issues.show', $head)
            ->with('status', __('issues.merge.flash.merged', ['count' => $issues->count()]));
    }

    /**
     * Löst eine Untergruppe wieder heraus.
     *
     * Angesprochen wird die **Untergruppe** und nicht der Kopf: es können
     * mehrere sein, und „lös die eine heraus" ist die Absicht. Ein Eintrag, der
     * gar nicht beigetreten ist, hat hier nichts zu tun — das ist kein leerer
     * Erfolg, sondern eine falsche Adresse.
     */
    public function destroy(Issue $issue): RedirectResponse
    {
        Gate::authorize('merge', $issue);

        abort_unless($issue->isMerged(), 404);

        IssueMerging::unmerge($issue);

        return back()->with('status', __('issues.merge.flash.unmerged'));
    }
}
