<?php

namespace Database\Factories;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Lease;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $rent = fake()->numberBetween(3000, 15000);
        $service = fake()->numberBetween(0, 1000);
        $penalty = fake()->boolean(20) ? fake()->numberBetween(100, 500) : 0;

        return [
            'lease_id' => Lease::factory(),
            'billing_start' => fake()->dateTimeBetween('-2 months', '-1 month'),
            'billing_end' => fake()->dateTimeBetween('-1 month', 'now'),
            'rent_amount' => $rent,
            'service_amount' => $service,
            'penalty_amount' => $penalty,
            'total_amount' => $rent + $service + $penalty,
            'due_date' => fake()->dateTimeBetween('now', '+2 weeks'),
            'status' => fake()->randomElement(InvoiceStatus::cases()),
        ];
    }
}
