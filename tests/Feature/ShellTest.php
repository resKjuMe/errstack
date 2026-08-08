<?php

namespace Tests\Feature;

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
     */
    private function signIn(): User
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        return $user;
    }

    public function test_the_example_page_renders_in_the_shell(): void
    {
        $this->signIn();

        $this->get('/')
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

        $this->get('/')
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

        $this->get('/')
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
            '/' => 'Übersicht',
            '/bausteine' => 'Bausteine',
            // Die Merkmal-Übersicht markiert sich selbst über ihr Muster
            // `tags.*` und nicht über die Adresse.
            '/merkmale' => 'Merkmale',
            // Die Auswertungsseite markiert sich über `performance.index`;
            // `performance.*` wäre hier falsch, darunter lägen auch die
            // Leistungsprobleme.
            '/leistung' => 'Leistung',
            '/leistungsprobleme' => 'Leistungsprobleme',
            // Das Ladeerlebnis ist ein eigener Eintrag: es misst, was der
            // Besucher erlebt, und nicht, was der Server braucht.
            '/ladeerlebnis' => 'Ladeerlebnis',
            // Die Profile liegen unterhalb von `/leistung`, gehören aber zu
            // ihrem eigenen Muster `profiling.*`: die Adresse allein würde hier
            // zwei Einträge gleichzeitig markieren.
            '/leistung/profile' => 'Profile',
            '/versionen' => 'Versionen',
            '/rueckmeldungen' => 'Rückmeldungen',
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

        $this->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('shell.user.name', $user->name)
                ->where('shell.user.email', $user->email)
                ->where('shell.logoutHref', route('logout'))
                ->where('shell.menu', fn (Collection $menu) => $menu->pluck('label')->all() === ['Profil', 'Benachrichtigungen', 'Zugriffstoken', 'Bausteine'])
            );
    }

    public function test_the_guest_state_carries_no_user(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('shell.user', null)
                ->where('shell.loginHref', route('login'))
            );
    }

    public function test_the_root_view_applies_the_theme_before_rendering(): void
    {
        $this->signIn();

        // Anti-Flash-Script: setzt die .dark-Klasse synchron im <head>.
        $this->get('/')
            ->assertOk()
            ->assertSee("localStorage.getItem('theme')", false)
            ->assertSee("classList.toggle('dark'", false);
    }

    public function test_flash_messages_are_shared_with_every_page(): void
    {
        $this->signIn();

        $this->withSession(['status' => 'Gespeichert.'])
            ->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('flash.status', 'Gespeichert.'));
    }
}
