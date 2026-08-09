<?php

namespace App\Support;

use App\Enums\Platform;
use App\Enums\ResolutionBehavior;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Nutzlast der Projekt-Seiten. Was der Betrachter tun darf, entscheiden auch
 * hier die Policies — die Oberfläche blendet nur aus, was ohnehin abgewiesen
 * würde.
 */
final class ProjectData
{
    /**
     * Projektliste einer Organisation.
     *
     * @return array<string, mixed>
     */
    public static function index(?Organization $organization, User $viewer): array
    {
        if ($organization === null) {
            return [
                'organization' => null,
                'permissions' => ['create' => false],
                'projects' => [],
                'platformOptions' => Platform::options(),
            ];
        }

        $projects = $organization->projects()->with('teams')->get();

        return [
            'organization' => [
                'slug' => $organization->slug,
                'name' => $organization->name,
                'href' => route('organizations.show', $organization),
            ],
            'permissions' => [
                'create' => Gate::forUser($viewer)->allows('manageProjects', $organization),
            ],
            'projects' => $projects
                ->sortBy(fn (Project $project): string => (string) $project->name)
                ->values()
                ->map(fn (Project $project): array => [
                    'slug' => $project->slug,
                    'name' => $project->name,
                    'platform' => $project->platform->value,
                    'platformLabel' => $project->platform->label(),
                    'platformShort' => $project->platform->shortLabel(),
                    'environment' => $project->default_environment,
                    'href' => route('projects.show', [$organization, $project]),
                    'teams' => $project->teams
                        ->sortBy(fn (Team $team): string => (string) $team->name)
                        ->pluck('name')
                        ->values()
                        ->all(),
                ])->all(),
            'platformOptions' => Platform::options(),
        ];
    }

    /**
     * Einstellungsseite eines Projekts: Stammdaten, Verhalten und zuständige
     * Teams. Die Client-Schlüssel haben eine eigene Seite (ProjectKeyData).
     *
     * @return array<string, mixed>
     */
    public static function detail(Project $project, User $viewer): array
    {
        $project->load(['organization', 'teams', 'environments']);
        $organization = $project->organization;

        $mayManage = Gate::forUser($viewer)->allows('update', $project);
        $mayManageKeys = Gate::forUser($viewer)->allows('manageKeys', $project);

        return [
            'project' => [
                'slug' => $project->slug,
                'name' => $project->name,
                'platform' => $project->platform->value,
                'platformLabel' => $project->platform->label(),
                'platformShort' => $project->platform->shortLabel(),
                'defaultEnvironment' => $project->default_environment,
                'resolutionBehavior' => $project->resolution_behavior->value,
                'retentionDays' => $project->retention_days,
                // Ob ein neuer Fehler von selbst an den Autor des
                // verdächtigsten Commits geht (R4). Angezeigt werden die
                // Verdächtigen immer; das hier ist die Frage, ob daraus auch
                // eine Zuständigkeit wird.
                'autoAssignSuspectCommits' => $project->auto_assign_suspect_commits,
                'href' => route('projects.show', [$organization, $project]),
                // Die DSN steht auf der Schlüssel-Seite; hier verweist nur der
                // Link darauf, und auch der nur für die Verwaltung.
                'keysHref' => $mayManageKeys ? route('projects.keys.index', [$organization, $project]) : null,
                // Der Einrichtungs-Assistent (O8) — aus demselben Grund an
                // dieselbe Bedingung geknüpft: er zeigt die DSN. Er steht hier,
                // damit der Ablauf jederzeit erneut aufrufbar ist und nicht nur
                // in der Minute nach dem Anlegen.
                'setupHref' => $mayManageKeys ? route('projects.setup.index', [$organization, $project]) : null,
                // Die Cronjob-Überwachung darf jedes Mitglied ansehen — der
                // Link steht deshalb ohne Bedingung da, anders als der zu den
                // Schlüsseln.
                'cronsHref' => route('projects.crons.index', [$organization, $project]),
                // Die Gruppierung ebenfalls ohne Bedingung: die Regeln
                // erklären, warum die Fehlerliste so aussieht, wie sie aussieht
                // — und diese Frage stellt sich nicht nur die Verwaltung.
                'groupingHref' => route('projects.grouping.index', [$organization, $project]),
                // Die Schwellwert-Alarme ebenfalls ohne Bedingung: welche
                // Alarme scharf sind, ist die erste Frage, wenn etwas **nicht**
                // gemeldet wurde — und die stellt nicht nur die Verwaltung.
                'alertsHref' => route('projects.alerts.index', [$organization, $project]),
                // Und die Alarm-Regeln für Fehler daneben, aus demselben Grund:
                // sie beantworten die Frage, warum eine Meldung kam — oder eben
                // nicht kam.
                'issueAlertsHref' => route('projects.issue-alerts.index', [$organization, $project]),
                // Die Alarm-Übersicht (A4) führt beide Arten zusammen: sie ist
                // die Seite für „was hat heute Nacht gefeuert?", während die
                // beiden darüber die Seiten zum Einrichten sind. Ebenfalls ohne
                // Bedingung — sie beantwortet dieselbe Frage wie jene, nur
                // rückblickend.
                'alertOverviewHref' => route('projects.alert-overview.index', [$organization, $project]),
                // Die Stichproben aus demselben Grund ohne Bedingung: sie
                // erklären, warum in der Performance-Übersicht mehr Aufrufe
                // stehen, als Messungen gespeichert sind.
                'samplingHref' => route('projects.sampling.index', [$organization, $project]),
                // Die Schwellen der Leistungserkennung ebenfalls ohne
                // Bedingung: sie erklären, warum ein bekanntes Problem **nicht**
                // in der Liste der Leistungsprobleme steht — und diese Frage
                // stellt sich der, der das Problem kennt, nicht die Verwaltung.
                'performanceHref' => route('projects.performance.index', [$organization, $project]),
                // Ebenso ohne Bedingung: was von einer Meldung gespeichert wird,
                // geht jeden an, der mit den Daten arbeitet.
                'privacyHref' => route('projects.privacy.index', [$organization, $project]),
                // Die Bündelung der Benachrichtigungen (A6) ebenfalls ohne
                // Bedingung: sie erklärt, warum eine Meldung erst mit
                // Verzögerung kam — und das fragt sich der, der auf sie
                // gewartet hat.
                'digestHref' => route('projects.digest.index', [$organization, $project]),
                // Und ebenso die Eingangsfilter: wer eine Meldung vermisst, muss
                // nachsehen können, ob ein Filter sie genommen hat.
                'filtersHref' => route('projects.filters.index', [$organization, $project]),
                // Der Ausschlag-Schutz (A7) aus demselben Grund und mit noch
                // mehr Anlass: eine Drosselung nimmt Meldungen ohne Ansehen
                // ihres Inhalts, und wer eine vermisst, findet die Zahl nur
                // dort.
                'spikesHref' => route('projects.spikes.index', [$organization, $project]),
                // Und die Zuständigkeits-Regeln (R6) aus demselben Grund: sie
                // beantworten „warum steht mein Name an diesem Fehler?", und
                // diese Frage stellt gerade der, der die Regeln nicht ändern
                // darf.
                'ownershipHref' => route('projects.ownership.index', [$organization, $project]),
            ],
            'organization' => [
                'slug' => $organization->slug,
                'name' => $organization->name,
                'href' => route('organizations.show', $organization),
            ],
            'permissions' => [
                'update' => $mayManage,
                'delete' => Gate::forUser($viewer)->allows('delete', $project),
                'manageTeams' => Gate::forUser($viewer)->allows('manageTeams', $project),
                'manageKeys' => $mayManageKeys,
                'manageCrons' => Gate::forUser($viewer)->allows('manageCrons', $project),
                'manageGrouping' => Gate::forUser($viewer)->allows('manageGrouping', $project),
                'manageFilters' => Gate::forUser($viewer)->allows('manageFilters', $project),
            ],
            'teams' => $organization->teams()
                ->orderBy('name')
                ->get()
                ->map(fn (Team $team): array => [
                    'id' => $team->id,
                    'name' => $team->name,
                    'assigned' => $project->teams->contains($team),
                    'href' => route('teams.show', $team),
                ])->all(),
            'environments' => $project->environments
                ->sortBy(fn (Environment $environment): string => (string) $environment->name)
                ->values()
                ->map(fn (Environment $environment): array => [
                    'id' => $environment->id,
                    'name' => $environment->name,
                    'hidden' => $environment->is_hidden,
                    'lastSeenAt' => Formats::dateTime($environment->last_seen_at),
                    'href' => route('projects.environments.update', [$organization, $project, $environment]),
                ])->all(),
            'platformOptions' => Platform::options(),
            'resolutionOptions' => ResolutionBehavior::options(),
        ];
    }
}
