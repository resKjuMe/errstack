<?php

namespace Tests\Feature;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ShellTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Der Rahmen ist seit der Anmeldung (F3) nur angemeldet zu sehen — jeder Test,
     * der ihn prüft, braucht ein Konto.
     *
     * Und seit U5 eine Organisation: die Fachseiten liegen unter
     * `/organisationen/{organisation}/…`, und ohne Mitgliedschaft gibt es diese
     * Adressen für dieses Konto nicht — die Leiste hätte dann nichts zu zeigen.
     */
    private function signIn(): User
    {
        $user = User::factory()->create();
        $user->switchOrganization(Organization::factory()->withMember($user)->create());

        $this->actingAs($user);

        return $user;
    }

    public function test_the_example_page_renders_in_the_shell(): void
    {
        $this->signIn();

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->has('shell')
            );
    }

    public function test_the_components_page_renders_in_the_shell(): void
    {
        $this->signIn();

        $this->get('/bausteine')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Components'));
    }

    /**
     * Die Navigation der Seitenleiste, flach gelesen: Beschriftung => aktiv.
     * Die Tests unten fragen nach dem Eintrag, nicht nach seiner Position —
     * eine neue Gruppe verschiebt sonst jede Erwartung.
     *
     * @return array<string, bool>
     */
    private function navState(AssertableInertia $page): array
    {
        $state = [];

        foreach ($page->toArray()['props']['shell']['nav'] as $group) {
            foreach ($group['links'] as $link) {
                $state[$link['label']] = $link['active'];
            }
        }

        return $state;
    }

    public function test_the_navigation_is_grouped_by_topic(): void
    {
        $this->signIn();

        $this->get(route('dashboard'))
            ->assertInertia(function (AssertableInertia $page) {
                $nav = $page->toArray()['props']['shell']['nav'];

                // Die Übersicht ist der Einstieg und steht ohne Überschrift
                // über den Gruppen.
                $this->assertNull($nav[0]['label']);
                $this->assertSame(['Übersicht'], array_column($nav[0]['links'], 'label'));

                $groups = [];

                foreach (array_slice($nav, 1) as $group) {
                    $groups[$group['label']] = array_column($group['links'], 'label');
                }

                $this->assertSame([
                    'Überwachen' => ['Fehler', 'Rückmeldungen', 'Merkmale'],
                    'Untersuchen' => ['Leistung', 'Leistungsprobleme', 'Ladeerlebnis', 'Profile'],
                    'Ausliefern' => ['Versionen'],
                    'Verwalten' => ['Projekte', 'Organisationen', 'Bausteine'],
                ], $groups);
            });
    }

    public function test_every_navigation_entry_carries_an_icon(): void
    {
        // Eingeklappt zeigt die Leiste nur Symbole — ein Eintrag ohne Icon wäre
        // dort ein leeres Kästchen.
        $this->signIn();

        $this->get(route('dashboard'))
            ->assertInertia(function (AssertableInertia $page) {
                foreach ($page->toArray()['props']['shell']['nav'] as $group) {
                    foreach ($group['links'] as $link) {
                        $this->assertArrayHasKey('icon', $link, "Ohne Icon: {$link['label']}");
                    }
                }
            });
    }

    public function test_the_navigation_marks_the_current_page_as_active(): void
    {
        $this->signIn();

        // Genau ein Eintrag ist hervorgehoben — die Antwort auf die Frage, wo
        // man gerade ist, gibt es nicht in doppelter Ausführung.
        $expectations = [
            route('dashboard') => 'Übersicht',
            route('components') => 'Bausteine',
            // Die Merkmal-Übersicht markiert sich selbst über ihr Muster
            // `tags.*` und nicht über die Adresse.
            route('tags.index') => 'Merkmale',
            // Die Auswertungsseite markiert sich über `performance.index`;
            // `performance.*` wäre hier falsch, darunter lägen auch die
            // Leistungsprobleme.
            route('performance.index') => 'Leistung',
            route('performance.issues.index') => 'Leistungsprobleme',
            // Das Ladeerlebnis ist ein eigener Eintrag: es misst, was der
            // Besucher erlebt, und nicht, was der Server braucht.
            route('web-vitals.index') => 'Ladeerlebnis',
            // Die Profile liegen unterhalb von `leistung`, gehören aber zu
            // ihrem eigenen Muster `profiling.*`: die Adresse allein würde hier
            // zwei Einträge gleichzeitig markieren.
            route('profiling.index') => 'Profile',
            route('releases.index') => 'Versionen',
            route('feedback.index') => 'Rückmeldungen',
        ];

        foreach ($expectations as $url => $expected) {
            $this->get($url)
                ->assertInertia(function (AssertableInertia $page) use ($url, $expected) {
                    $active = array_keys(array_filter($this->navState($page)));

                    $this->assertSame([$expected], $active, "Hervorgehoben auf {$url}");
                });
        }
    }

    public function test_the_shell_knows_the_signed_in_user_and_the_account_menu(): void
    {
        $user = $this->signIn();

        $this->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('shell.user.name', $user->name)
                ->where('shell.user.email', $user->email)
                ->where('shell.logoutHref', route('logout'))
                // Die Benachrichtigungen stehen seit U2 als Anker im Fuß und
                // nicht mehr im Nutzer-Menü.
                ->where('shell.menu', fn (Collection $menu) => $menu->pluck('label')->all() === ['Profil', 'Zugriffstoken', 'Bausteine'])
            );
    }

    public function test_the_guest_state_carries_no_user(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('shell.user', null)
                ->where('shell.loginHref', route('login'))
                // Ohne Konto gibt es keine Organisation — und damit keinen
                // Umschalter, der eine anzeigen könnte.
                ->where('shell.org', null)
            );
    }

    public function test_the_shell_names_the_current_organization_and_offers_the_others(): void
    {
        $user = User::factory()->create();
        $current = Organization::factory()->withMember($user, OrganizationRole::Owner)->create(['name' => 'Anker AG']);
        $other = Organization::factory()->withMember($user, OrganizationRole::Member)->create(['name' => 'Zeppelin GmbH']);

        $user->switchOrganization($current);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('shell.org.current.name', 'Anker AG')
                ->where('shell.org.current.slug', $current->slug)
                // Zwei Wörter, zwei Anfangsbuchstaben.
                ->where('shell.org.current.initials', 'AA')
                // Die aktive Organisation steht nicht im Menü: dort führt sie
                // nur auf sich selbst.
                ->where('shell.org.options', fn (Collection $options) => $options->pluck('name')->all() === ['Zeppelin GmbH']
                    && $options->pluck('switchHref')->all() === [route('organizations.switch', $other)]
                )
                ->where('shell.org.createHref', route('organizations.index'))
            );
    }

    public function test_a_single_organization_leaves_only_the_create_entry(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user, OrganizationRole::Owner)->create(['name' => 'Solo']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('shell.org.current.name', 'Solo')
                // Ein einzelnes Wort gibt zwei Buchstaben her.
                ->where('shell.org.current.initials', 'SO')
                ->where('shell.org.options', [])
                ->where('shell.org.createHref', route('organizations.index'))
            );

        $this->assertSame($organization->id, $user->refresh()->current_organization_id);
    }

    public function test_the_chosen_organization_survives_a_page_change(): void
    {
        $user = User::factory()->create();
        $current = Organization::factory()->withMember($user, OrganizationRole::Owner)->create(['name' => 'Anker AG']);
        $other = Organization::factory()->withMember($user, OrganizationRole::Member)->create(['name' => 'Zeppelin GmbH']);

        $user->switchOrganization($current);

        // Genau der Weg, den der Umschalter geht: POST auf organizations.switch.
        // Seit U5 steht die Organisation in der Adresse — der Wechsel führt
        // deshalb auf dieselbe Seite unter dem neuen Slug und nicht zurück auf
        // die Adresse der alten.
        $this->actingAs($user)
            ->from(route('issues.index', $current))
            ->post(route('organizations.switch', $other))
            ->assertRedirect(route('issues.index', $other));

        // Anschließend auf einer anderen Seite: die Wahl ist der Adresse
        // gefolgt und steckt am Konto, nicht in der Sitzung einer einzelnen
        // Ansicht.
        $this->get(route('dashboard', $other))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('shell.org.current.name', 'Zeppelin GmbH')
                ->where('shell.org.options', fn (Collection $options) => $options->pluck('name')->all() === ['Anker AG'])
            );
    }

    public function test_the_footer_anchors_lead_to_settings_and_notifications(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user, OrganizationRole::Owner)->create();

        $user->switchOrganization($organization);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(function (AssertableInertia $page) use ($organization) {
                $footer = $page->toArray()['props']['shell']['footer'];

                $this->assertSame(
                    ['Einstellungen', 'Benachrichtigungen'],
                    array_column($footer, 'label'),
                );

                // Die Einstellungen zeigen auf die aktive Organisation.
                $this->assertSame(route('organizations.show', $organization), $footer[0]['href']);
                $this->assertSame(route('notifications.preferences'), $footer[1]['href']);

                // Eingeklappt zeigt die Leiste nur Symbole — wie in der
                // Navigation braucht jeder Anker eines.
                foreach ($footer as $link) {
                    $this->assertArrayHasKey('icon', $link, "Ohne Icon: {$link['label']}");
                }
            });
    }

    public function test_the_settings_anchor_falls_back_to_the_overview_without_an_organization(): void
    {
        // Ein frisch registriertes Konto gehört noch keiner Organisation an. Der
        // Anker führt dann zur Übersicht — von dort entsteht die erste. Sie ist
        // hier auch die aufgerufene Seite: die Fachseiten liegen seit U5 unter
        // einer Organisation und gibt es für dieses Konto noch nicht.
        $this->actingAs(User::factory()->create());

        $this->get(route('organizations.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('shell.org.current', null)
                ->where('shell.org.options', [])
                ->where('shell.footer.0.href', route('organizations.index'))
            );
    }

    public function test_the_root_view_applies_the_theme_before_rendering(): void
    {
        $this->signIn();

        // Anti-Flash-Script: setzt die .dark-Klasse synchron im <head>.
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee("localStorage.getItem('theme')", false)
            ->assertSee("classList.toggle('dark'", false);
    }

    public function test_flash_messages_are_shared_with_every_page(): void
    {
        $this->signIn();

        $this->withSession(['status' => 'Gespeichert.'])
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('flash.status', 'Gespeichert.'));
    }
}
