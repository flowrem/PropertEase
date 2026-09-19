<?php

namespace Database\Factories;

use App\Enums\LeaseStatus;
use App\Models\Lease;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lease>
 */
class LeaseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = fake()->dateTimeBetween('-1 year', 'now');
        $end = (clone $start)->modify('+1 year');

        return [
            'unit_id' => Unit::factory(),
            'tenant_id' => User::factory(),
            'start_date' => $start,
            'end_date' => $end,
            'due_day' => fake()->numberBetween(1, 28),
            'status' => fake()->randomElement(LeaseStatus::cases()),
        ];
    }
}
