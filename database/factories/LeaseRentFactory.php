<?php

namespace Database\Factories;

use App\Models\Lease;
use App\Models\LeaseRent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeaseRent>
 */
class LeaseRentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lease_id' => Lease::factory(),
            'amount' => fake()->numberBetween(3000, 15000),
            'effective_date' => fake()->dateTimeBetween('-1 year', 'now'),
        ];
    }
}
