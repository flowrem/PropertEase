<?php

namespace Database\Factories;

use App\Enums\UnitItemType;
use App\Models\Unit;
use App\Models\UnitItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UnitItem>
 */
class UnitItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'unit_id' => Unit::factory(),
            'name' => ucfirst(fake()->word()).' '.fake()->word(),
            'item_type' => fake()->randomElement(UnitItemType::cases()),
        ];
    }

    /**
     * An item taken out of future checks.
     */
    public function removed(): static
    {
        return $this->state(fn () => ['removed_at' => now()]);
    }
}
