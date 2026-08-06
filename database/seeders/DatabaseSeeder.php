<?php

namespace Database\Seeders;

use App\Enums\OrganizationRole;
use App\Models\Organization;
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
        $organization->teams()->create(['name' => 'Plattform']);

        $user->switchOrganization($organization);
    }
}
