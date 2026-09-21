<?php

namespace Database\Factories;

use App\Enums\UnitStatus;
use App\Models\Property;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Unit>
 */
class UnitFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'unit_number' => (string) fake()->unique()->numberBetween(100, 999),
            'floor_level' => (string) fake()->numberBetween(1, 10),
            'bedrooms' => fake()->numberBetween(0, 4),
            'bathrooms' => fake()->numberBetween(1, 3),
            'status' => fake()->randomElement(UnitStatus::cases()),
            'allows_multiple_tenants' => false,
            'price' => fake()->numberBetween(2000, 15000),
        ];
    }
}
