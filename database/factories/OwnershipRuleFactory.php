<?php

namespace Database\Factories;

use App\Enums\OwnershipMatcher;
use App\Models\OwnershipRule;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OwnershipRule>
 */
class OwnershipRuleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'matcher' => OwnershipMatcher::Path,
            'tag_key' => null,
            'pattern' => 'src/billing/*',
            'owners' => ['#Kasse'],
            'source' => OwnershipRule::SOURCE_MANUAL,
            'position' => 0,
            'is_active' => true,
        ];
    }

    /**
     * Eine Regel auf ein anderes Merkmal der Meldung.
     *
     * @param  list<string>  $owners
     */
    public function matching(OwnershipMatcher $matcher, string $pattern, array $owners, ?string $tagKey = null): static
    {
        return $this->state(fn (): array => [
            'matcher' => $matcher,
            'pattern' => $pattern,
            'owners' => $owners,
            'tag_key' => $tagKey,
        ]);
    }

    /**
     * @param  list<string>  $owners
     */
    public function ownedBy(array $owners): static
    {
        return $this->state(fn (): array => ['owners' => $owners]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function at(int $position): static
    {
        return $this->state(fn (): array => ['position' => $position]);
    }
}
