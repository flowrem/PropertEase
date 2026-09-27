<?php

namespace Database\Factories;

use App\Models\IssueType;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IssueType>
 */
class IssueTypeFactory extends Factory
{
    /**
     * Define the model's default state: a team's own issue type.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => ucfirst(fake()->unique()->word()).' '.fake()->word(),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
