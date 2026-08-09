<?php

namespace App\Support;

use App\Http\Middleware\ResolveOrganization;
use App\Http\Middleware\SettingsArea;
use App\Models\Project;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * Die Unter-Navigation des Einstellungsbereichs (U6).
 *
 * Sie ist nach der Frage gegliedert, **woran** eingestellt wird: an der
 * Organisation, an einem Projekt, an der Aufnahme der Daten, an der Zustellung
 * von Benachrichtigungen, am eigenen Konto. Wer eine Einstellung sucht, muss
 * damit nicht mehr wissen, in welcher Ecke der Anwendung sie liegt — er sucht
 * sie in der Gruppe, zu der sie gehört.
 *
 * Die projektbezogenen Einträge erscheinen erst, wenn ein Projekt feststeht: sie
 * hängen an einem und wären ohne es Links ins Leere. Welches gemeint ist, steht
 * in der Adresse; die Gruppe nennt es als `context`, damit sichtbar bleibt,
 * woran man gerade schraubt.
 *
 * Gebaut wird die Leiste nur im Einstellungsbereich
 * ({@see SettingsArea}) — auf den Auswertungsseiten gibt es
 * sie nicht, so wie es hier die globale Filterleiste nicht gibt.
 */
final class SettingsNav
{
    /**
     * @return array{title: string, groups: list<array{label: string, context: string|null, links: list<array{label: string, href: string, active: bool}>}>}
     */
    public static function build(): array
    {
        $project = self::currentProject();

        return [
            'title' => __('nav.footer.settings'),
            'groups' => self::groups($project),
        ];
    }

    /**
     * @return list<array{label: string, context: string|null, links: list<array{label: string, href: string, active: bool}>}>
     */
    private static function groups(?Project $project): array
    {
        $params = $project === null ? [] : ['project' => $project];

        $groups = [
            [
                'label' => __('nav.settings.groups.organization'),
                'context' => null,
                'entries' => [
                    [
                        'label' => __('nav.settings.links.organization'),
                        'route' => 'organizations.show',
                        // Mitglieder, Teams und offene Einladungen stehen auf
                        // der Stammdatenseite; die Teamseite ist ihre
                        // Detailansicht und hebt deshalb denselben Eintrag
                        // hervor.
                        'activePattern' => ['organizations.show', 'teams.show'],
                    ],
                    [
                        'label' => __('nav.settings.links.audit_log'),
                        'route' => 'organizations.audit-log.index',
                        'activePattern' => 'organizations.audit-log.*',
                    ],
                    [
                        'label' => __('nav.settings.links.repositories'),
                        'route' => 'organizations.repositories.index',
                        'activePattern' => 'organizations.repositories.*',
                    ],
                    [
                        'label' => __('nav.settings.links.integrations'),
                        'route' => 'organizations.integrations.index',
                        'activePattern' => 'organizations.integrations.*',
                    ],
                    [
                        'label' => __('nav.settings.links.organization_quotas'),
                        'route' => 'organizations.quotas.index',
                        'activePattern' => 'organizations.quotas.*',
                    ],
                    [
                        'label' => __('nav.settings.links.organizations'),
                        'route' => 'organizations.index',
                        'activePattern' => 'organizations.index',
                    ],
                ],
            ],
            [
                'label' => __('nav.settings.groups.projects'),
                'context' => $project?->name,
                'entries' => [
                    [
                        'label' => __('nav.settings.links.projects'),
                        'route' => 'projects.index',
                        'activePattern' => 'projects.index',
                    ],
                    ...self::forProject($project, $params, [
                        // Die Umgebungen stehen mit auf der Stammdatenseite —
                        // einstellbar ist an ihnen nur die Sichtbarkeit, und
                        // eine eigene Seite dafür wäre eine Seite mit einer
                        // Spalte.
                        ['project', 'projects.show', 'projects.show'],
                        ['project_setup', 'projects.setup.index', 'projects.setup.*'],
                        ['project_keys', 'projects.keys.index', 'projects.keys.*'],
                        ['project_ownership', 'projects.ownership.index', 'projects.ownership.*'],
                        ['project_grouping', 'projects.grouping.index', 'projects.grouping.*'],
                        ['project_alerts', 'projects.alerts.index', 'projects.alerts.*'],
                        ['project_issue_alerts', 'projects.issue-alerts.index', 'projects.issue-alerts.*'],
                        ['project_crons', 'projects.crons.index', 'projects.crons.*'],
                        ['project_uptime', 'projects.uptime.index', 'projects.uptime.*'],
                        ['project_performance', 'projects.performance.index', 'projects.performance.*'],
                        ['project_quotas', 'projects.quotas.index', 'projects.quotas.*'],
                    ]),
                ],
            ],
            [
                'label' => __('nav.settings.groups.privacy'),
                'context' => $project?->name,
                'entries' => [
                    [
                        'label' => __('nav.settings.links.organization_privacy'),
                        'route' => 'organizations.privacy.index',
                        'activePattern' => 'organizations.privacy.*',
                    ],
                    ...self::forProject($project, $params, [
                        ['project_privacy', 'projects.privacy.index', 'projects.privacy.*'],
                        ['project_filters', 'projects.filters.index', 'projects.filters.*'],
                        ['project_sampling', 'projects.sampling.index', 'projects.sampling.*'],
                        ['project_spikes', 'projects.spikes.index', 'projects.spikes.*'],
                    ]),
                ],
            ],
            [
                'label' => __('nav.settings.groups.notifications'),
                'context' => $project?->name,
                'entries' => [
                    [
                        'label' => __('nav.settings.links.notification_channels'),
                        'route' => 'notifications.index',
                        'activePattern' => 'notifications.index',
                    ],
                    [
                        'label' => __('nav.settings.links.notification_preferences'),
                        'route' => 'notifications.preferences',
                        'activePattern' => 'notifications.preferences*',
                    ],
                    ...self::forProject($project, $params, [
                        ['project_digest', 'projects.digest.index', 'projects.digest.*'],
                    ]),
                ],
            ],
            [
                'label' => __('nav.settings.groups.account'),
                'context' => null,
                'entries' => [
                    [
                        'label' => __('nav.settings.links.profile'),
                        'route' => 'profile.edit',
                        'activePattern' => 'profile.*',
                    ],
                    [
                        'label' => __('nav.settings.links.api_tokens'),
                        'route' => 'api-tokens.index',
                        'activePattern' => 'api-tokens.*',
                    ],
                ],
            ],
        ];

        $navigation = [];

        foreach ($groups as $group) {
            $links = NavLinks::build($group['entries']);

            // Eine Gruppe ohne erreichbaren Eintrag fällt samt Überschrift weg —
            // sonst stünde in der Leiste eine leere Rubrik. Das trifft ohne
            // Organisation die meisten.
            if ($links === []) {
                continue;
            }

            $navigation[] = [
                'label' => $group['label'],
                'context' => $group['context'],
                'links' => $links,
            ];
        }

        return $navigation;
    }

    /**
     * Die projektbezogenen Einträge einer Gruppe — oder nichts, solange kein
     * Projekt feststeht.
     *
     * @param  array<string, mixed>  $params
     * @param  list<array{0: string, 1: string, 2: string}>  $entries  Sprachschlüssel, Route, Muster
     * @return list<array{label: string, route: string, activePattern: string, params: array<string, mixed>}>
     */
    private static function forProject(?Project $project, array $params, array $entries): array
    {
        if ($project === null) {
            return [];
        }

        return array_map(fn (array $entry): array => [
            'label' => __('nav.settings.links.'.$entry[0]),
            'route' => $entry[1],
            'activePattern' => $entry[2],
            'params' => $params,
        ], $entries);
    }

    /**
     * Das Projekt aus der Adresse, sofern die laufende Route eines trägt.
     *
     * Beide Formen sind bedacht — dieselbe Vorsicht wie bei der Organisation in
     * {@see ResolveOrganization}: die Bindung liefert das
     * Modell nur, wenn der Controller einen gleichnamigen, typisierten Parameter
     * hat; sonst steht im Routen-Parameter noch der Slug. Der Slug ist nur
     * innerhalb einer Organisation eindeutig — deshalb wird er auch nur dort
     * gesucht, so wie `scopeBindings` es auf den Projektrouten tut. Hier hängt
     * daran keine Rechteprüfung, nur die Beschriftung: ohne Treffer bleibt die
     * Gruppe eben bei ihrer Liste.
     */
    private static function currentProject(): ?Project
    {
        $value = RouteFacade::current()?->parameter('project');

        if ($value instanceof Project) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        return CurrentOrganization::for(request())
            ?->projects()
            ->where('slug', $value)
            ->first();
    }
}
