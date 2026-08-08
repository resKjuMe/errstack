<?php

namespace App\Http\Controllers;

use App\Enums\PerformanceProblem;
use App\Http\Requests\PerformanceSettingRequest;
use App\Models\Organization;
use App\Models\PerformanceSetting;
use App\Models\Project;
use App\Support\PerformanceSettingData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Die Schwellen der Leistungserkennung je Projekt.
 *
 * **Gespeichert wird nur, was abweicht.** Ein Muster, das läuft und auf seinen
 * Vorgabewerten steht, bekommt keine Zeile — und eine bestehende wird
 * gelöscht, sobald jemand sie auf die Vorgabe zurückstellt. Der Aufwand dafür
 * sind drei Zeilen, und er entscheidet, ob ein später verbesserter Vorgabewert
 * die bestehenden Projekte erreicht oder an ihnen vorbeiläuft
 * ({@see PerformanceSetting}).
 */
class ProjectPerformanceController extends Controller
{
    public function index(Request $request, Organization $organization, Project $project): InertiaResponse
    {
        Gate::authorize('view', $project);

        return Inertia::render('projects/Performance', PerformanceSettingData::forProject($project, $request->user()));
    }

    public function update(
        PerformanceSettingRequest $request,
        Organization $organization,
        Project $project,
    ): RedirectResponse {
        Gate::authorize('managePerformance', $project);

        foreach ($request->settings() as $value => $setting) {
            $problem = PerformanceProblem::from($value);

            // `==` und nicht `===`: verglichen wird, ob dieselben Schwellen
            // dieselben Werte haben, nicht ob zwei Felder in derselben
            // Reihenfolge aufgebaut wurden. Der strenge Vergleich würde bei
            // vertauschten Schlüsseln eine Zeile anlegen, die nichts abweicht —
            // und damit genau die Bindung an einen alten Vorgabewert erzeugen,
            // die diese Abfrage verhindern soll.
            if ($setting['enabled'] && $setting['thresholds'] == $problem->defaults()) {
                $project->performanceSettings()->where('problem', $problem->value)->delete();

                continue;
            }

            $project->performanceSettings()->updateOrCreate(
                ['problem' => $problem->value],
                [
                    'is_enabled' => $setting['enabled'],
                    // Auch bei einem abgeschalteten Muster: wer es wieder
                    // einschaltet, will seine Werte vorfinden und nicht die
                    // Vorgabe.
                    'thresholds' => $setting['thresholds'],
                ],
            );
        }

        return back()->with('status', __('performance_issues.flash.settings_updated'));
    }
}
