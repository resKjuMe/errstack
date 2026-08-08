<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

/**
 * Nutzlast für das React-Grundgerüst (resources/js/shell): Navigations-Links,
 * Nutzer-Menü, Labels. Wird als Inertia-Shared-Data bei jedem Aufruf frisch
 * berechnet, damit die aktiven Links pro Navigation stimmen.
 *
 * Ohne Anmeldung — auf den Gast-Seiten (Anmelden, Registrieren, Passwort
 * zurücksetzen) — liefert `user` null und die Shell zeigt einen Gast-Zustand;
 * Menü-Einträge auf noch fehlende Routen werden herausgefiltert, statt auf tote
 * Links zu zeigen.
 */
final class ShellData
{
    /**
     * @return array<string, mixed>
     */
    public static function build(): array
    {
        $user = Auth::user();

        return [
            'appName' => config('app.name', 'Errstack'),
            'user' => $user === null ? null : [
                'name' => $user->name,
                'email' => $user->email,
            ],
            // Ohne Organisation führt das Logo auf den Einstieg: der entscheidet
            // selbst, wohin es geht — auf die Übersicht einer Organisation oder
            // in die Liste, wo sich eine anlegen lässt.
            'logoHref' => self::hasOrganization() ? route('dashboard') : url('/'),
            // Der Rückweg zur zuletzt ausgeführten Aktion (S6). Er steht in der
            // Hülle und nicht an der Seite, weil die Meldung samt Schaltfläche
            // in der Hülle erscheint — und weil eine Aktion aus der Liste auf
            // der Detailseite landen kann und umgekehrt.
            'undoHref' => self::hasOrganization() ? route('issues.actions.undo') : null,
            'logoutHref' => Route::has('logout') ? route('logout') : null,
            'loginHref' => Route::has('login') ? route('login') : null,
            'csrf' => csrf_token(),
            'broadcast' => self::broadcast(),
            'nav' => self::nav(),
            'menu' => self::menu(),
            'labels' => [
                'guest' => __('nav.guest'),
                'signIn' => __('nav.sign_in'),
                'signOut' => __('nav.sign_out'),
                'menu' => __('nav.menu'),
                'help' => __('common.show_help'),
                'sidebar' => [
                    'collapse' => __('nav.sidebar.collapse'),
                    'expand' => __('nav.sidebar.expand'),
                ],
                'theme' => [
                    'light' => __('nav.theme.light'),
                    'dark' => __('nav.theme.dark'),
                    'system' => __('nav.theme.system'),
                ],
            ],
        ];
    }

    /**
     * Verbindungsdaten für den Websocket-Client (pusher-js). Der Schlüssel ist
     * öffentlich; Secret und App-ID bleiben serverseitig. Ist nichts
     * konfiguriert, liefert `enabled` false und der Client verbindet gar nicht.
     *
     * Lokal zeigt das auf den selbst gehosteten Reverb, in der Produktion auf
     * Pusher Cloud — dieselbe Verbindung, nur andere Werte.
     *
     * `authEndpoint` und `csrf` braucht der Client nur für **private** Kanäle:
     * dort holt er sich vor dem Abo eine Unterschrift bei der Anwendung, und
     * routes/channels.php entscheidet. Die Adresse steht hier und nicht in der
     * Oberfläche, damit es eine Stelle bleibt, wenn sie sich einmal ändert.
     *
     * @return array{enabled: bool, key: string|null, cluster: string|null, host: string|null, port: int|null, scheme: string|null, channel: string, authEndpoint: string, csrf: string}
     */
    private static function broadcast(): array
    {
        $connection = config('broadcasting.default');
        $options = config("broadcasting.connections.{$connection}.options", []);
        $key = config("broadcasting.connections.{$connection}.key");
        $scheme = $options['scheme'] ?? 'https';

        return [
            'enabled' => in_array($connection, ['reverb', 'pusher'], true) && filled($key),
            'key' => $key,
            'cluster' => $options['cluster'] ?? null,
            'host' => $options['host'] ?? null,
            'port' => isset($options['port']) ? (int) $options['port'] : null,
            'scheme' => $scheme,
            'channel' => 'demo',
            'authEndpoint' => '/broadcasting/auth',
            'csrf' => csrf_token(),
        ];
    }

    /**
     * Primärnavigation der Seitenleiste, nach Themen gruppiert.
     *
     * Die Gruppen folgen der Frage, was man gerade tut: laufend hinsehen
     * (Überwachen), einer Auffälligkeit nachgehen (Untersuchen), eine
     * Auslieferung nachvollziehen (Ausliefern), den Rahmen einrichten
     * (Verwalten). Die Übersicht steht als Einstieg ohne Gruppe darüber.
     *
     * Eine Gruppe, deren Einträge sämtlich auf noch fehlende Routen zeigen,
     * fällt samt Überschrift weg — sonst stünde in der Leiste eine leere
     * Rubrik.
     *
     * @return list<array{label: string|null, links: list<array{label: string, href: string, active: bool, icon?: string}>}>
     */
    private static function nav(): array
    {
        $groups = [
            [
                'label' => null,
                'entries' => [
                    [
                        'label' => __('nav.links.dashboard'),
                        'route' => 'dashboard',
                        'activePattern' => 'dashboard',
                        'icon' => 'dashboard',
                    ],
                ],
            ],
            [
                'label' => __('nav.groups.monitor'),
                'entries' => [
                    [
                        'label' => __('nav.links.issues'),
                        'route' => 'issues.index',
                        'activePattern' => 'issues.*',
                        'icon' => 'issues',
                    ],
                    [
                        'label' => __('nav.links.feedback'),
                        'route' => 'feedback.index',
                        'activePattern' => 'feedback.*',
                        'icon' => 'feedback',
                    ],
                    [
                        'label' => __('nav.links.tags'),
                        'route' => 'tags.index',
                        'activePattern' => 'tags.*',
                        'icon' => 'tags',
                    ],
                ],
            ],
            [
                'label' => __('nav.groups.investigate'),
                'entries' => [
                    [
                        'label' => __('nav.links.performance'),
                        'route' => 'performance.index',
                        // Nicht `performance.*`: darunter läge auch die
                        // Leistungsproblem-Liste, und beide Einträge stünden
                        // dann gleichzeitig hervorgehoben in der Leiste.
                        'activePattern' => 'performance.index',
                        'icon' => 'performance',
                    ],
                    [
                        'label' => __('nav.links.performance_issues'),
                        'route' => 'performance.issues.index',
                        'activePattern' => 'performance.issues.*',
                        'icon' => 'performance_issues',
                    ],
                    [
                        'label' => __('nav.links.web_vitals'),
                        'route' => 'web-vitals.index',
                        'activePattern' => 'web-vitals.*',
                        'icon' => 'web_vitals',
                    ],
                    [
                        'label' => __('nav.links.profiling'),
                        'route' => 'profiling.index',
                        'activePattern' => 'profiling.*',
                        'icon' => 'profiling',
                    ],
                ],
            ],
            [
                'label' => __('nav.groups.ship'),
                'entries' => [
                    [
                        'label' => __('nav.links.releases'),
                        'route' => 'releases.index',
                        'activePattern' => 'releases.*',
                        'icon' => 'releases',
                    ],
                ],
            ],
            [
                'label' => __('nav.groups.manage'),
                'entries' => [
                    [
                        'label' => __('nav.links.projects'),
                        'route' => 'projects.index',
                        'activePattern' => 'projects.*',
                        'icon' => 'projects',
                    ],
                    [
                        'label' => __('nav.links.organizations'),
                        'route' => 'organizations.index',
                        'activePattern' => 'organizations.*',
                        'icon' => 'organizations',
                    ],
                    [
                        'label' => __('nav.links.components'),
                        'route' => 'components',
                        'activePattern' => 'components',
                        'icon' => 'components',
                    ],
                ],
            ],
        ];

        $nav = [];

        foreach ($groups as $group) {
            $links = self::withExisting($group['entries']);

            if ($links === []) {
                continue;
            }

            $nav[] = [
                'label' => $group['label'],
                'links' => $links,
            ];
        }

        return $nav;
    }

    /**
     * Einträge des Nutzer-Menüs (Dropdown rechts, im Mobil-Menü unten).
     *
     * @return list<array{label: string, href: string, active: bool, icon?: string}>
     */
    private static function menu(): array
    {
        return self::withExisting([
            [
                'label' => __('nav.menu_items.profile'),
                'route' => 'profile.edit',
                'activePattern' => 'profile.*',
                'icon' => 'profile',
            ],
            // Der Zustand der Installation (O5). Im Nutzer-Menü und nicht in
            // der Kopfzeile: die Seite wird selten gebraucht, und wenn, dann
            // gezielt. Sichtbar nur für den Betreiber — steht der Eintrag bei
            // allen, führt er die Mehrheit auf eine Seite, die sie nicht sehen
            // darf.
            ...(Gate::allows('operations') ? [[
                'label' => __('nav.menu_items.operations'),
                'route' => 'operations.index',
                'activePattern' => 'operations.*',
                'icon' => 'pulse',
            ]] : []),
            [
                'label' => __('nav.menu_items.notifications'),
                'route' => 'notifications.preferences',
                'activePattern' => 'notifications.preferences*',
                'icon' => 'bell',
            ],
            [
                'label' => __('nav.menu_items.api_tokens'),
                'route' => 'api-tokens.index',
                'activePattern' => 'api-tokens.*',
                'icon' => 'key',
            ],
            [
                'label' => __('nav.menu_items.components'),
                'route' => 'components',
                'activePattern' => 'components',
                'icon' => 'components',
            ],
        ]);
    }

    /**
     * Steht für diese Anfrage eine Organisation fest? Die Fachseiten liegen unter
     * `/organisationen/{organisation}/…`, und ihre Adressen entstehen aus der
     * Vorbelegung, die App\Http\Middleware\ResolveOrganization hinterlegt. Ohne
     * sie — auf den Gast-Seiten und bei einem Konto ohne Mitgliedschaft — gibt es
     * diese Adressen nicht, und ein Link darauf wäre keiner.
     */
    private static function hasOrganization(): bool
    {
        return filled(URL::getDefaultParameters()['organization'] ?? null);
    }

    /**
     * Baut Links aus Routen-Namen und lässt Einträge weg, deren Route (noch)
     * nicht existiert — oder die eine Organisation brauchen, die es gerade nicht
     * gibt.
     *
     * @param  list<array{label: string, route: string, activePattern: string, icon?: string}>  $entries
     * @return list<array{label: string, href: string, active: bool, icon?: string}>
     */
    private static function withExisting(array $entries): array
    {
        $links = [];

        foreach ($entries as $entry) {
            $route = Route::getRoutes()->getByName($entry['route']);

            if ($route === null) {
                continue;
            }

            if (! self::hasOrganization() && in_array('organization', $route->parameterNames(), true)) {
                continue;
            }

            $link = [
                'label' => $entry['label'],
                'href' => route($entry['route']),
                'active' => request()->routeIs($entry['activePattern']),
            ];

            if (isset($entry['icon'])) {
                $link['icon'] = $entry['icon'];
            }

            $links[] = $link;
        }

        return $links;
    }
}
