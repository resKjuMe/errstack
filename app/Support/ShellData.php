<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

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
            'logoHref' => route('dashboard'),
            'logoutHref' => Route::has('logout') ? route('logout') : null,
            'loginHref' => Route::has('login') ? route('login') : null,
            'csrf' => csrf_token(),
            'broadcast' => self::broadcast(),
            'links' => self::links(),
            'menu' => self::menu(),
            'labels' => [
                'guest' => __('nav.guest'),
                'signIn' => __('nav.sign_in'),
                'signOut' => __('nav.sign_out'),
                'menu' => __('nav.menu'),
                'help' => __('common.show_help'),
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
     * Primärnavigation (Kopfzeile und Mobil-Menü).
     *
     * @return list<array{label: string, href: string, active: bool, icon?: string}>
     */
    private static function links(): array
    {
        return self::withExisting([
            [
                'label' => __('nav.links.dashboard'),
                'route' => 'dashboard',
                'activePattern' => 'dashboard',
            ],
            [
                'label' => __('nav.links.issues'),
                'route' => 'issues.index',
                'activePattern' => 'issues.*',
            ],
            [
                'label' => __('nav.links.performance'),
                'route' => 'performance.index',
                'activePattern' => 'performance.*',
            ],
            [
                'label' => __('nav.links.projects'),
                'route' => 'projects.index',
                'activePattern' => 'projects.*',
            ],
            [
                'label' => __('nav.links.organizations'),
                'route' => 'organizations.index',
                'activePattern' => 'organizations.*',
            ],
            [
                'label' => __('nav.links.components'),
                'route' => 'components',
                'activePattern' => 'components',
                'icon' => 'components',
            ],
        ]);
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
     * Baut Links aus Routen-Namen und lässt Einträge weg, deren Route (noch)
     * nicht existiert.
     *
     * @param  list<array{label: string, route: string, activePattern: string, icon?: string}>  $entries
     * @return list<array{label: string, href: string, active: bool, icon?: string}>
     */
    private static function withExisting(array $entries): array
    {
        $links = [];

        foreach ($entries as $entry) {
            if (! Route::has($entry['route'])) {
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
