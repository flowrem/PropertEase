<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\ReservationStatus;
use App\Models\PaymentChannel;
use App\Models\Reservation;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reservation>
 */
class ReservationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => Reservation::generateCode(),
            'unit_listing_id' => null,
            'unit_id' => Unit::factory(),
            'team_id' => fn (array $attributes) => Unit::query()->whereKey($attributes['unit_id'])->firstOrFail()->property->team_id,
            'desired_username' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'age' => fake()->numberBetween(18, 40),
            'address' => fake()->address(),
            'valid_id_path' => 'reservations/'.fake()->uuid().'.jpg',
            'downpayment_amount' => fake()->numberBetween(1000, 5000),
            'payment_channel_id' => fn (array $attributes) => PaymentChannel::factory()->for(Unit::query()->whereKey($attributes['unit_id'])->firstOrFail()->property->team),
            'downpayment_method' => PaymentMethod::Gcash,
            'downpayment_reference' => (string) fake()->numerify('#############'),
            'downpayment_proof_path' => 'reservations/'.fake()->uuid().'.png',
            'consented_at' => now(),
        ];
    }

    public function status(ReservationStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
