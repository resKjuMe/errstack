<?php

namespace Database\Factories;

use App\Enums\AuditAction;
use App\Models\AuditLogEntry;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Nur für Tests, die einen Bestand an Einträgen brauchen (Filter, Export,
 * Seitenweise). Im Betrieb entstehen Einträge ausschließlich über
 * App\Support\AuditLog.
 *
 * @extends Factory<AuditLogEntry>
 */
class AuditLogEntryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'actor_id' => User::factory(),
            'actor_name' => fake()->name(),
            'actor_email' => fake()->unique()->safeEmail(),
            'action' => AuditAction::MembershipRoleChanged,
            'subject_label' => fake()->name(),
            'changed_values' => ['Rolle' => ['before' => 'Lesend', 'after' => 'Mitglied']],
            'ip_address' => fake()->ipv4(),
        ];
    }

    /**
     * Eintrag im Namen eines bestimmten Kontos — Name und Adresse werden dabei
     * mitgeschrieben, genau wie beim echten Protokollieren.
     */
    public function by(User $user): static
    {
        return $this->state(fn (): array => [
            'actor_id' => $user->id,
            'actor_name' => $user->name,
            'actor_email' => $user->email,
        ]);
    }
}
