<?php

namespace App\Support;

use App\Enums\PerformanceProblem;
use App\Models\Project;
use App\Models\User;
use App\Support\Performance\Detection\Thresholds;
use Illuminate\Support\Facades\Gate;

/**
 * Die Einstellungsseite der Leistungserkennung, wie die Oberfläche sie braucht.
 *
 * Je Muster: was es findet, ob es läuft, welche Schwellen gelten — und
 * ausdrücklich auch, **welche Vorgabe** gälte. Das Letzte ist kein Beiwerk: ohne
 * sie sieht ein eingestellter Wert genauso aus wie ein geerbter, und niemand
 * weiß mehr, ob hier jemand etwas entschieden hat oder ob das nur so
 * dasteht. Und wer eine Einstellung zurücknehmen will, hätte keine Ahnung,
 * worauf.
 */
final class PerformanceSettingData
{
    /**
     * @return array<string, mixed>
     */
    public static function forProject(Project $project, User $viewer): array
    {
        $project->loadMissing('organization');
        $organization = $project->organization;

        $thresholds = Thresholds::forProject($project);
        $defaults = Thresholds::defaults();

        return [
            'organization' => [
                'slug' => $organization->slug,
                'name' => $organization->name,
                'href' => route('organizations.show', $organization),
            ],
            'project' => [
                'slug' => $project->slug,
                'name' => $project->name,
                'href' => route('projects.show', [$organization, $project]),
                'updateHref' => route('projects.performance.update', [$organization, $project]),
            ],
            'problems' => array_map(
                static fn (PerformanceProblem $problem): array => self::problem($problem, $thresholds, $defaults),
                PerformanceProblem::cases(),
            ),
            'issuesHref' => route('performance.issues.index'),
            'permissions' => ['manage' => Gate::forUser($viewer)->allows('managePerformance', $project)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function problem(PerformanceProblem $problem, Thresholds $thresholds, Thresholds $defaults): array
    {
        $limits = $problem->limits();

        $values = [];

        foreach ($thresholds->all($problem) as $key => $value) {
            $values[] = [
                'key' => $key,
                'label' => $problem->thresholdLabel($key),
                'value' => $value,
                'default' => $defaults->raw($problem, $key),
                'min' => $limits[$key]['min'] ?? 0,
                'max' => $limits[$key]['max'] ?? 0,
            ];
        }

        return [
            'value' => $problem->value,
            'label' => $problem->label(),
            'description' => $problem->description(),
            'enabled' => $thresholds->isEnabled($problem),
            'thresholds' => $values,
        ];
    }
}
