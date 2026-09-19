<?php

namespace Database\Factories;

use App\Enums\PropertyType;
use App\Models\Property;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Property>
 */
class PropertyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => fake()->company().' '.fake()->randomElement(['Residences', 'Suites', 'Place', 'Court']),
            'address_line' => fake()->streetAddress(),
            'city' => fake()->city(),
            'province' => fake()->randomElement(['Metro Manila', 'Cebu', 'Davao del Sur', 'Laguna', 'Cavite', 'Bulacan']),
            'postal_code' => fake()->postcode(),
            'type' => fake()->randomElement(PropertyType::cases()),
        ];
    }
}
