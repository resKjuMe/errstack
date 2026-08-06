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
                'guest' => 'Gast',
                'signIn' => 'Anmelden',
                'signOut' => 'Abmelden',
                'menu' => 'Menü',
                'help' => 'Hilfe anzeigen',
                'theme' => [
                    'light' => 'Helles Design',
                    'dark' => 'Dunkles Design',
                    'system' => 'Design des Systems',
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
     * @return array{enabled: bool, key: string|null, cluster: string|null, host: string|null, port: int|null, scheme: string|null, channel: string}
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
                'label' => 'Übersicht',
                'route' => 'dashboard',
                'activePattern' => 'dashboard',
            ],
            [
                'label' => 'Organisationen',
                'route' => 'organizations.index',
                'activePattern' => 'organizations.*',
            ],
            [
                'label' => 'Bausteine',
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
                'label' => 'Profil',
                'route' => 'profile.edit',
                'activePattern' => 'profile.*',
                'icon' => 'profile',
            ],
            [
                'label' => 'Bausteine',
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
