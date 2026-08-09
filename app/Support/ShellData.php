<?php

namespace App\Support;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Support\Filters\CurrentFilter;
use App\Support\Filters\FilterQuery;
use App\Support\Filters\RememberedFilter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

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

        // Die aktive Organisation über resolveCurrentOrganization() und nicht
        // über das Feld selbst: die Leiste soll dieselbe Organisation nennen,
        // mit der die Seiten arbeiten (ProjectController, GlobalFilter …).
        // Zeigt das Feld auf eine Organisation, der das Konto nicht mehr
        // angehört, zieht die Methode es nach — sonst stünde oben ein Name, den
        // keine Seite darunter benutzt.
        $organization = $user?->resolveCurrentOrganization();

        return [
            'appName' => config('app.name', 'Errstack'),
            'user' => $user === null ? null : [
                'name' => $user->name,
                'email' => $user->email,
            ],
            // Ohne Organisation führt das Logo auf den Einstieg: der entscheidet
            // selbst, wohin es geht — auf die Übersicht einer Organisation oder
            // in die Liste, wo sich eine anlegen lässt. Mit Organisation führt es
            // auf eine Auswertungsseite und trägt den Filter deshalb mit — sonst
            // wäre ausgerechnet der auffälligste Weg zur Übersicht der eine, der
            // die Adresse leerräumt.
            'logoHref' => self::hasOrganization() ? self::filtered(route('dashboard')) : url('/'),
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
            'org' => $user === null ? null : self::org($user, $organization),
            'footer' => self::footer($organization),
            'labels' => [
                'guest' => __('nav.guest'),
                'signIn' => __('nav.sign_in'),
                'signOut' => __('nav.sign_out'),
                'menu' => __('nav.menu'),
                'help' => __('common.show_help'),
                'org' => [
                    'label' => __('nav.org.label'),
                    'switch' => __('nav.org.switch'),
                    'create' => __('nav.org.create'),
                    'none' => __('nav.org.none'),
                ],
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
                        'filtered' => true,
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
                        'filtered' => true,
                    ],
                    [
                        'label' => __('nav.links.feedback'),
                        'route' => 'feedback.index',
                        'activePattern' => 'feedback.*',
                        'icon' => 'feedback',
                        'filtered' => true,
                    ],
                    [
                        'label' => __('nav.links.tags'),
                        'route' => 'tags.index',
                        'activePattern' => 'tags.*',
                        'icon' => 'tags',
                        'filtered' => true,
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
                        'filtered' => true,
                    ],
                    [
                        'label' => __('nav.links.performance_issues'),
                        'route' => 'performance.issues.index',
                        'activePattern' => 'performance.issues.*',
                        'icon' => 'performance_issues',
                        'filtered' => true,
                    ],
                    [
                        'label' => __('nav.links.web_vitals'),
                        'route' => 'web-vitals.index',
                        'activePattern' => 'web-vitals.*',
                        'icon' => 'web_vitals',
                        'filtered' => true,
                    ],
                    [
                        'label' => __('nav.links.profiling'),
                        'route' => 'profiling.index',
                        'activePattern' => 'profiling.*',
                        'icon' => 'profiling',
                        'filtered' => true,
                    ],
                    // Die Aufzeichnungen stehen unter „Untersuchen" und nicht
                    // unter „Überwachen": sie melden nichts und lösen nichts
                    // aus. Man kommt zu ihnen, weil man eine Frage hat — und im
                    // Regelfall nicht über diesen Eintrag, sondern von einem
                    // Fehler aus (M3).
                    [
                        'label' => __('nav.links.replays'),
                        'route' => 'replays.index',
                        'activePattern' => 'replays.*',
                        'icon' => 'replays',
                        'filtered' => true,
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
                        'filtered' => true,
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
     * Der Umschalter am Kopf der Seitenleiste: die aktive Organisation, die
     * übrigen zur Auswahl und der Weg zu einer neuen.
     *
     * `options` enthält bewusst **nicht** die aktive Organisation: sie steht
     * schon in der Schaltfläche darüber, und ein Eintrag, der auf den eigenen
     * Zustand wechselt, wäre nur ein Klick ins Leere. Wer zu genau einer
     * Organisation gehört, findet im Menü daher allein den Anlege-Eintrag.
     *
     * Gewechselt wird über `organizations.switch` — dieselbe Route, die die
     * Organisationsseite benutzt. Ein zweiter Weg, die aktive Organisation zu
     * setzen, entsteht hier nicht.
     *
     * @return array{current: array{name: string, slug: string, initials: string}|null, options: list<array{name: string, slug: string, initials: string, switchHref: string}>, createHref: string|null}
     */
    private static function org(User $user, ?Organization $current): array
    {
        $others = $user->memberships()
            ->with('organization')
            ->get()
            ->reject(fn (Membership $membership): bool => $membership->organization_id === $current?->id)
            ->sortBy(fn (Membership $membership): string => (string) $membership->organization->name)
            ->values();

        return [
            'current' => $current === null ? null : [
                'name' => $current->name,
                'slug' => $current->slug,
                'initials' => self::initials($current->name),
            ],
            'options' => $others->map(fn (Membership $membership): array => [
                'name' => $membership->organization->name,
                'slug' => $membership->organization->slug,
                'initials' => self::initials($membership->organization->name),
                'switchHref' => route('organizations.switch', $membership->organization),
            ])->all(),
            // Anlegen darf nicht jeder (OrganizationPolicy::create). Steht der
            // Eintrag trotzdem im Menü, führt er in eine abgelehnte Anfrage.
            // Ziel ist die Übersicht: dort steht das Formular.
            'createHref' => Gate::allows('create', Organization::class)
                ? route('organizations.index')
                : null,
        ];
    }

    /**
     * Kürzel der Organisation: bis zu zwei Anfangsbuchstaben. Es steht neben dem
     * Namen und ist in der eingeklappten Leiste alles, was von der Organisation
     * bleibt — der Slug wäre dort zu lang.
     */
    private static function initials(string $name): string
    {
        $words = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $letters = array_map(
            fn (string $word): string => Str::upper(Str::substr($word, 0, 1)),
            array_slice($words, 0, 2),
        );

        // Ein einzelnes Wort gibt zwei Buchstaben her („Errstack" → „ER").
        if (count($letters) === 1) {
            return Str::upper(Str::substr($words[0], 0, 2));
        }

        return implode('', $letters);
    }

    /**
     * Die festen Anker im Fuß der Seitenleiste: Einstellungen und
     * Benachrichtigungen.
     *
     * Die Einstellungen zeigen auf die aktive Organisation — dort wird
     * eingerichtet, was für alle darunter gilt. Ohne Organisation bleibt die
     * Übersicht als Ziel: von dort führt der Weg zur ersten. Umgeräumt werden
     * die Einstellungsseiten selbst in U6.
     *
     * @return list<array{label: string, href: string, active: bool, icon?: string}>
     */
    private static function footer(?Organization $current): array
    {
        $settings = $current === null
            ? ['route' => 'organizations.index', 'activePattern' => 'organizations.index']
            : ['route' => 'organizations.show', 'params' => [$current], 'activePattern' => 'organizations.show'];

        return self::withExisting([
            [
                'label' => __('nav.footer.settings'),
                'icon' => 'settings',
                ...$settings,
            ],
            [
                'label' => __('nav.footer.notifications'),
                'route' => 'notifications.preferences',
                'activePattern' => 'notifications.preferences*',
                'icon' => 'bell',
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
            // Die Benachrichtigungen stehen seit U2 als eigener Anker im Fuß der
            // Leiste (self::footer) und hier deshalb nicht mehr: derselbe Weg
            // zweimal in derselben Leiste ist einer zu viel.
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
     * `params` braucht nur, wer auf eine Route mit Platzhalter zeigt — etwa die
     * Einstellungen der aktiven Organisation.
     *
     * `filtered` markiert die Auswertungsseiten: ihre Links tragen den Filter
     * dieses Aufrufs mit, damit die Auswahl den Seitenwechsel übersteht. Der
     * Grund, warum das nicht dem gemerkten Stand allein überlassen bleibt, steht
     * bei {@see self::filtered()}.
     *
     * @param  list<array{label: string, route: string, activePattern: string, params?: array<array-key, mixed>, icon?: string, filtered?: bool}>  $entries
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

            $href = route($entry['route'], $entry['params'] ?? []);

            $link = [
                'label' => $entry['label'],
                'href' => ($entry['filtered'] ?? false) ? self::filtered($href) : $href,
                'active' => request()->routeIs($entry['activePattern']),
            ];

            if (isset($entry['icon'])) {
                $link['icon'] = $entry['icon'];
            }

            $links[] = $link;
        }

        return $links;
    }

    /**
     * Ein Link auf eine Auswertungsseite, mit dem Filter dieses Aufrufs.
     *
     * **Warum die Links ihn überhaupt mittragen.** Der gemerkte Stand allein
     * würde am Ziel dasselbe zeigen ({@see RememberedFilter}),
     * aber die Adresse dort bliebe nackt — und eine Adresse, die den gezeigten
     * Ausschnitt nicht nennt, ist nicht mehr teilbar, nicht mehr als Lesezeichen
     * zu gebrauchen und im Verlauf von jeder anderen nicht zu unterscheiden. Der
     * Filter steht in der Adresszeile, also gehört er auch in die Links, die
     * dorthin führen.
     *
     * Genommen wird der bereits aufgelöste Filter der laufenden Anfrage: er ist
     * ohnehin da, wenn die Seite eine Auswertungsseite ist, und er ist um
     * gelöschte Projekte und Umgebungen schon bereinigt. Ist keiner da — auf
     * Einstellungen, Profil und Verwaltung —, bleiben die Links ohne Parameter;
     * dort greift am Ziel der gemerkte Stand.
     */
    private static function filtered(string $href): string
    {
        $filter = CurrentFilter::of(request());
        $query = $filter === null ? '' : FilterQuery::build($filter);

        return $query === '' ? $href : $href.'?'.$query;
    }
}
