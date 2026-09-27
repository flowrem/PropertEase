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
            'floor_level' => fake()->randomElement(array_slice(Unit::floorLevelOptions(), 1, 10)),
            'bedrooms' => fake()->numberBetween(2, 4),
            'bathrooms' => fake()->numberBetween(1, 3),
            'floor_area_sqm' => 60,
            'status' => fake()->randomElement(UnitStatus::cases()),
            'allows_multiple_tenants' => false,
            'price' => fake()->numberBetween(2000, 15000),
        ];
    }

    /**
     * A unit created before floor area was recorded, whose details are not locked yet.
     */
    public function withoutFloorArea(): static
    {
        return $this->state(fn (array $attributes) => [
            'floor_area_sqm' => null,
        ]);
    }
}
