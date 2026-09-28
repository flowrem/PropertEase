<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\ReservationStatus;
use App\Enums\StayType;
use App\Models\PaymentChannel;
use App\Models\Reservation;
use App\Models\Unit;
use Carbon\CarbonInterface;
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
            'contact_number' => '+639'.fake()->numerify('#########'),
            'address' => fake()->address(),
            'stay_type' => StayType::LongTerm,
            'valid_id_path' => 'reservations/'.fake()->uuid().'.jpg',
            'consented_at' => now(),
        ];
    }

    /**
     * The applicant has paid and sent proof of the downpayment.
     */
    public function downpaymentSent(): static
    {
        return $this->state(fn () => [
            'downpayment_amount' => fake()->numberBetween(1000, 5000),
            'payment_channel_id' => fn (array $attributes) => PaymentChannel::factory()->for(Unit::query()->whereKey($attributes['unit_id'])->firstOrFail()->property->team),
            'downpayment_method' => PaymentMethod::Gcash,
            'downpayment_reference' => (string) fake()->numerify('#############'),
            'downpayment_proof_path' => 'reservations/'.fake()->uuid().'.png',
            'downpayment_submitted_at' => now(),
        ]);
    }

    /**
     * Downpayment received and checked by the landlord.
     */
    public function confirmed(): static
    {
        return $this->downpaymentSent()->state(fn () => [
            'status' => ReservationStatus::Confirmed,
            'expires_at' => now()->addDays(3),
            'downpayment_confirmed_at' => now(),
        ]);
    }

    public function status(ReservationStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    /**
     * Accepted by the landlord and holding the unit until the deadline, with
     * no downpayment sent yet.
     */
    public function reserved(?CarbonInterface $expiresAt = null): static
    {
        return $this->state(fn () => [
            'status' => ReservationStatus::Reserved,
            'expires_at' => $expiresAt ?? now()->addDays(3),
        ]);
    }
}
