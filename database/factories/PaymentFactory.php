<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'amount_paid' => fake()->numberBetween(1000, 15000),
            'method' => fake()->randomElement(PaymentMethod::cases()),
            'reference_number' => fake()->bothify('REF-########'),
            'receipt_path' => null,
            'paid_at' => fake()->dateTimeBetween('-1 month', 'now'),
        ];
    }
}
