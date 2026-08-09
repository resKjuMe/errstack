<?php

namespace Tests\Feature;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Der Einstellungsbereich (U6): alles, was eingerichtet wird, unter einer
 * Adresse und hinter einer eigenen Unter-Navigation — getrennt von den Seiten,
 * auf denen Daten angesehen werden.
 *
 * Geprüft wird der Schnitt, nicht der Inhalt der einzelnen Seiten: dass sie
 * unter `/einstellungen/…` liegen, dass die Unter-Navigation sie führt, dass die
 * Hauptnavigation sie nicht mehr führt, dass die alten Adressen weiterhin ans
 * Ziel kommen — und dass der Umzug nichts geöffnet hat, was vorher zu war.
 */
class SettingsAreaTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(OrganizationRole $role = OrganizationRole::Owner): User
    {
        $user = User::factory()->create();
        $user->switchOrganization(Organization::factory()->withMember($user, $role)->create());

        $this->actingAs($user);

        return $user;
    }

    /**
     * Die Unter-Navigation, flach gelesen: Gruppe => Beschriftungen.
     *
     * @return array<string, list<string>>
     */
    private function settingsNav(AssertableInertia $page): array
    {
        $groups = [];

        foreach ($page->toArray()['props']['shell']['settings']['groups'] as $group) {
            $groups[$group['label']] = array_column($group['links'], 'label');
        }

        return $groups;
    }

    public function test_the_settings_pages_live_under_one_address(): void
    {
        $this->signIn();

        foreach ([
            'organizations.index',
            'projects.index',
            'notifications.preferences',
            'profile.edit',
            'api-tokens.index',
        ] as $name) {
            $this->assertStringStartsWith(
                '/einstellungen/',
                parse_url(route($name), PHP_URL_PATH),
                "Nicht im Einstellungsbereich: {$name}",
            );
        }
    }

    public function test_the_sub_navigation_is_grouped_by_what_is_being_configured(): void
    {
        $this->signIn();

        $this->get(route('organizations.index'))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $this->assertSame([
                    'Organisation',
                    'Projekte',
                    'Datenschutz und Aufnahme',
                    'Benachrichtigungen',
                    'Konto',
                ], array_keys($this->settingsNav($page)));
            });
    }

    public function test_the_account_group_holds_profile_and_access_tokens(): void
    {
        $this->signIn();

        $this->get(route('api-tokens.index'))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $this->assertSame(
                    ['Profil', 'Zugriffstoken'],
                    $this->settingsNav($page)['Konto'],
                );
            });
    }

    public function test_the_project_entries_appear_only_with_a_project_in_the_address(): void
    {
        $user = $this->signIn();
        $project = Project::factory()->for($user->resolveCurrentOrganization())->create(['name' => 'Zahlungsdienst']);

        // Ohne Projekt führt die Gruppe nur die Liste — die übrigen Einträge
        // hingen an keinem und wären Links ins Leere.
        $this->get(route('projects.index'))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $this->assertSame(['Alle Projekte'], $this->settingsNav($page)['Projekte']);
            });

        $this->get(route('projects.keys.index', $project))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $nav = $this->settingsNav($page);

                $this->assertContains('Schlüssel und DSN', $nav['Projekte']);
                $this->assertContains('Zuständigkeit', $nav['Projekte']);
                $this->assertContains('Einrichtung', $nav['Projekte']);
                // Aufnahme und Datenschutz des Projekts stehen in ihrer eigenen
                // Gruppe und nicht bei den Projekt-Stammdaten.
                $this->assertContains('Eingangsfilter', $nav['Datenschutz und Aufnahme']);
                $this->assertContains('Stichproben', $nav['Datenschutz und Aufnahme']);
            });
    }

    public function test_the_sub_navigation_names_the_project_it_belongs_to(): void
    {
        $user = $this->signIn();
        $project = Project::factory()->for($user->resolveCurrentOrganization())->create(['name' => 'Zahlungsdienst']);

        // „Stammdaten" und „Datenschutz" gibt es auf beiden Ebenen — ohne den
        // Zusatz sähe man der Leiste nicht an, welche gerade gemeint ist.
        $this->get(route('projects.show', $project))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $contexts = [];

                foreach ($page->toArray()['props']['shell']['settings']['groups'] as $group) {
                    $contexts[$group['label']] = $group['context'];
                }

                $this->assertSame('Zahlungsdienst', $contexts['Projekte']);
                $this->assertNull($contexts['Konto']);
            });
    }

    public function test_the_sub_navigation_marks_the_current_page(): void
    {
        $this->signIn();

        $this->get(route('organizations.audit-log.index'))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $active = [];

                foreach ($page->toArray()['props']['shell']['settings']['groups'] as $group) {
                    foreach ($group['links'] as $link) {
                        if ($link['active']) {
                            $active[] = $link['label'];
                        }
                    }
                }

                $this->assertSame(['Änderungsprotokoll'], $active);
            });
    }

    public function test_the_evaluation_pages_have_no_sub_navigation(): void
    {
        $this->signIn();

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('shell.settings', null));
    }

    public function test_the_settings_pages_carry_no_global_filter(): void
    {
        $this->signIn();

        // Die Hülle zeichnet die Leiste aus dieser Nutzlast (U3). Steht dort
        // null, kann sie nicht erscheinen — unabhängig davon, was eine Seite
        // rendert.
        foreach (['organizations.index', 'projects.index', 'api-tokens.index'] as $name) {
            $this->get(route($name))
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page->where('filter', null));
        }
    }

    public function test_the_main_navigation_no_longer_offers_projects_and_organizations(): void
    {
        $this->signIn();

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $labels = [];

                foreach ($page->toArray()['props']['shell']['nav'] as $group) {
                    $labels = [...$labels, ...array_column($group['links'], 'label')];
                }

                $this->assertNotContains('Projekte', $labels);
                $this->assertNotContains('Organisationen', $labels);
            });
    }

    public function test_the_access_tokens_have_left_the_user_menu(): void
    {
        $this->signIn();

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $labels = array_column($page->toArray()['props']['shell']['menu'], 'label');

                $this->assertNotContains('Zugriffstoken', $labels);
                // Das Profil bleibt: es ist das Konto selbst.
                $this->assertContains('Profil', $labels);
            });
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function movedAddresses(): array
    {
        return [
            'Organisationsliste' => ['/organisationen', 'organizations.index'],
            'Projektliste' => ['/projekte', 'projects.index'],
            'Zugriffstoken' => ['/zugriffstoken', 'api-tokens.index'],
            'Profil' => ['/profile', 'profile.edit'],
            'eigene Benachrichtigungen' => ['/benachrichtigungen/einstellungen', 'notifications.preferences'],
        ];
    }

    #[DataProvider('movedAddresses')]
    public function test_the_old_addresses_lead_to_the_new_ones(string $old, string $route): void
    {
        $this->signIn();

        $this->get($old)->assertRedirect(route($route));
    }

    public function test_an_old_address_keeps_its_query_string(): void
    {
        $user = $this->signIn();
        $organization = $user->resolveCurrentOrganization();

        // In den Abfrage-Parametern steckt die Auswahl im Änderungsprotokoll;
        // ein Link ohne sie zeigt etwas anderes als der, den jemand verschickt
        // hat.
        $this->get('/organisationen/'.$organization->slug.'/protokoll?akteur=42')
            ->assertRedirect(route('organizations.audit-log.index', $organization).'?akteur=42');
    }

    public function test_an_old_address_of_an_evaluation_page_still_belongs_to_that_page(): void
    {
        $user = $this->signIn();
        $organization = $user->resolveCurrentOrganization();

        // Die Weiterleitung greift nur, wo keine echte Route liegt: unter
        // `/organisationen/{slug}/…` stehen weiterhin die Fachseiten.
        $this->get(route('issues.index', $organization))->assertOk();
    }

    public function test_a_click_on_a_project_in_the_issue_list_still_opens_the_project(): void
    {
        $user = $this->signIn();
        $project = Project::factory()->for($user->resolveCurrentOrganization())->create();

        $this->get(route('projects.show', $project))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('projects/Show'));
    }

    public function test_the_move_opens_nothing_that_was_closed_before(): void
    {
        $this->signIn();

        // Eine fremde Organisation bleibt fremd — die Prüfung steckt
        // unverändert in der Middleware bzw. den Policies, nicht im Pfad.
        $foreign = Organization::factory()->create();

        $this->get(route('organizations.show', $foreign))->assertForbidden();
        $this->get(route('organizations.audit-log.index', $foreign))->assertForbidden();
    }

    public function test_the_settings_area_stays_behind_the_sign_in(): void
    {
        $this->get(route('organizations.index'))->assertRedirect(route('login'));
        $this->get(route('api-tokens.index'))->assertRedirect(route('login'));
    }
}
