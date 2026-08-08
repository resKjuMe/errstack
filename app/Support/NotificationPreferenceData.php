<?php

namespace App\Support;

use App\Enums\NotificationEventType;
use App\Enums\NotificationTransport;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Notifications\NotificationPreferences;
use App\Notifications\PreferenceScope;
use Illuminate\Support\Facades\Date;

/**
 * Nutzlast der persönlichen Benachrichtigungs-Übersicht.
 *
 * Sie liefert bewusst nicht nur die gespeicherten Entscheidungen, sondern zu
 * jeder Zelle auch den daraus folgenden Zustand. „Erbt" allein sagt niemandem,
 * ob am Ende eine Mail kommt — und genau das ist die Frage, die man vor der
 * Bereitschaft beantwortet haben will.
 */
final class NotificationPreferenceData
{
    /**
     * @return array<string, mixed>
     */
    public static function index(User $viewer, NotificationPreferences $preferences): array
    {
        $organizations = $viewer->organizations()
            ->with(['projects' => fn ($query) => $query->orderBy('name')])
            ->orderBy('organizations.name')
            ->get();

        $scopes = [self::globalScope($viewer, $preferences)];

        foreach ($organizations as $organization) {
            $scopes[] = self::organizationScope($viewer, $preferences, $organization);

            foreach ($organization->projects as $project) {
                $scopes[] = self::projectScope($viewer, $preferences, $project);
            }
        }

        $settings = $viewer->notificationSettingOrDefault();

        return [
            'events' => array_map(static fn (NotificationEventType $event): array => [
                'value' => $event->value,
                'label' => $event->label(),
                'description' => $event->description(),
                'critical' => $event->isCritical(),
            ], NotificationEventType::cases()),
            'transports' => array_map(static fn (NotificationTransport $transport): array => [
                'value' => $transport->value,
                'label' => $transport->label(),
                'description' => $transport->description(),
            ], NotificationTransport::cases()),
            'scopes' => $scopes,
            'quietHours' => [
                'enabled' => $settings->quiet_hours_enabled,
                'from' => substr($settings->quiet_from, 0, 5),
                'until' => substr($settings->quiet_until, 0, 5),
                'timezone' => $settings->timezone,
                'timezones' => timezone_identifiers_list(),
                'activeUntil' => $settings->quietUntilLabel(Date::now()),
            ],
            // Die Bündelung (A6): das Projekt entscheidet, ob gebündelt wird,
            // der Einzelne darf hier für sich widersprechen.
            'digestEnabled' => $settings->digest_enabled,
            'unsubscribedAt' => Formats::dateTime($settings->unsubscribed_at),
            // Kritische Alarme dürfen ausgeschaltet werden — aber nie
            // unbemerkt. Was hier steht, zeigt die Oberfläche als Warnung.
            'mutedCritical' => self::mutedCritical($scopes),
            'hrefs' => [
                'update' => route('notifications.preferences.update'),
                'quietHours' => route('notifications.preferences.quiet-hours'),
                'subscription' => route('notifications.preferences.subscription'),
                'digest' => route('notifications.preferences.digest'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function globalScope(User $viewer, NotificationPreferences $preferences): array
    {
        $scope = PreferenceScope::global();

        return [
            'key' => $scope->key(),
            'parentKey' => null,
            'kind' => 'global',
            'label' => __('notifications.preferences.scope_global'),
            'hint' => __('notifications.preferences.scope_global_hint'),
            'inherits' => false,
            'rows' => self::rows($viewer, $preferences, $scope, null, null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function organizationScope(User $viewer, NotificationPreferences $preferences, Organization $organization): array
    {
        $scope = PreferenceScope::forOrganization($organization);

        return [
            'key' => $scope->key(),
            'parentKey' => 'global',
            'kind' => 'organization',
            'label' => $organization->name,
            'hint' => __('notifications.preferences.scope_organization_hint'),
            'inherits' => true,
            'rows' => self::rows($viewer, $preferences, $scope, null, $organization),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function projectScope(User $viewer, NotificationPreferences $preferences, Project $project): array
    {
        $scope = PreferenceScope::forProject($project);

        return [
            'key' => $scope->key(),
            'parentKey' => "organization:{$project->organization_id}",
            'kind' => 'project',
            'label' => $project->name,
            'hint' => __('notifications.preferences.scope_project_hint'),
            'inherits' => true,
            'rows' => self::rows($viewer, $preferences, $scope, $project, null),
        ];
    }

    /**
     * Eine Zeile je Anlass, darin eine Zelle je Weg.
     *
     * @return array<string, array<string, array{choice: string, effective: bool}>>
     */
    private static function rows(
        User $viewer,
        NotificationPreferences $preferences,
        PreferenceScope $scope,
        ?Project $project,
        ?Organization $organization,
    ): array {
        $rows = [];

        foreach (NotificationEventType::cases() as $event) {
            foreach (NotificationTransport::cases() as $transport) {
                $decision = $preferences->decision($viewer, $scope->key(), $event, $transport);

                $rows[$event->value][$transport->value] = [
                    'choice' => match ($decision) {
                        true => 'on',
                        false => 'off',
                        null => 'inherit',
                    },
                    'effective' => $preferences->wants($viewer, $event, $transport, $project, $organization),
                ];
            }
        }

        return $rows;
    }

    /**
     * Bereiche, in denen ein kritischer Anlass tatsächlich nirgends mehr
     * ankommt. Nicht die ausdrückliche Entscheidung zählt, sondern das
     * Ergebnis — auch ein geerbtes „aus" ist eines.
     *
     * Gemeldet wird nur die oberste Stelle, an der es kippt: wer „Überall"
     * abschaltet, hat es damit in jeder Organisation und jedem Projekt
     * abgeschaltet, und zwanzig gleichlautende Zeilen lesen sich niemandem
     * als Warnung, sondern als Rauschen. Die Bereiche kommen von grob nach
     * fein, der übergeordnete steht also immer schon fest.
     *
     * @param  list<array<string, mixed>>  $scopes
     * @return list<array{scope: string, event: string}>
     */
    private static function mutedCritical(array $scopes): array
    {
        $muted = [];
        /** @var array<string, array<string, true>> $inherited je Bereich die dort schon gemeldeten Anlässe */
        $inherited = [];

        foreach ($scopes as $scope) {
            /** @var array<string, array<string, array{choice: string, effective: bool}>> $rows */
            $rows = $scope['rows'];
            $key = (string) $scope['key'];
            $fromParent = $inherited[(string) ($scope['parentKey'] ?? '')] ?? [];
            $inherited[$key] = $fromParent;

            foreach (NotificationEventType::critical() as $event) {
                $cells = $rows[$event->value] ?? [];
                $reachable = array_filter($cells, static fn (array $cell): bool => $cell['effective']);

                if ($reachable !== []) {
                    unset($inherited[$key][$event->value]);

                    continue;
                }

                $inherited[$key][$event->value] = true;

                if (isset($fromParent[$event->value])) {
                    continue;
                }

                $muted[] = [
                    'scope' => (string) $scope['label'],
                    'event' => $event->label(),
                ];
            }
        }

        return $muted;
    }
}
