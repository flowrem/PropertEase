<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Models\PaymentChannel;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentChannel>
 */
class PaymentChannelFactory extends Factory
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
            'method' => PaymentMethod::Gcash,
            'account_name' => fake()->name(),
            'account_number' => '09'.fake()->numerify('#########'),
            'bank_name' => null,
            'qr_path' => 'qr/'.fake()->uuid().'.png',
            'is_active' => true,
        ];
    }

    public function bank(): static
    {
        return $this->state(fn () => [
            'method' => PaymentMethod::BankTransfer,
            'bank_name' => 'BDO',
            'account_number' => fake()->numerify('##########'),
            'qr_path' => null,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
