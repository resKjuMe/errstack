<?php

namespace Database\Seeders;

use App\Enums\OrganizationRole;
use App\Enums\Platform;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        $user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        // Ohne Organisation liefe die lokale Installation ins Leere — alle
        // fachlichen Daten hängen an einer.
        $organization = Organization::createNamed('Beispiel GmbH');
        $organization->setRole($user, OrganizationRole::Owner);
        $team = $organization->teams()->create(['name' => 'Plattform']);

        // Ein Projekt je überwachter Anwendung. Zwei genügen, um Liste und
        // Team-Zuordnung lokal zu sehen.
        $shop = Project::createFor($organization, 'Webshop', Platform::Php);
        $shop->teams()->attach($team);

        Project::createFor($organization, 'Kundenportal', Platform::JavaScript);

        $user->switchOrganization($organization);
    }
}
