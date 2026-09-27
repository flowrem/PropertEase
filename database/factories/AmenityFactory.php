<?php

namespace Database\Factories;

use App\Enums\AmenityCategory;
use App\Models\Amenity;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Amenity>
 */
class AmenityFactory extends Factory
{
    /**
     * Define the model's default state: a team's own custom amenity.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => ucfirst(fake()->unique()->word()).' '.fake()->word(),
            'category' => AmenityCategory::Other,
            'is_active' => true,
        ];
    }

    /**
     * A platform default, visible to every team.
     */
    public function platformDefault(): static
    {
        return $this->state(fn () => ['team_id' => null]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
