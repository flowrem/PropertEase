<?php

namespace Database\Factories;

use App\Enums\ListingStatus;
use App\Models\Unit;
use App\Models\UnitListing;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UnitListing>
 */
class UnitListingFactory extends Factory
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
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'contact_name' => fake()->name(),
            'contact_phone' => '09'.fake()->numerify('#########'),
            'contact_email' => fake()->safeEmail(),
            'downpayment_amount' => fake()->numberBetween(1000, 5000),
            'status' => ListingStatus::Draft,
        ];
    }

    public function pendingReview(): static
    {
        return $this->state(fn () => [
            'status' => ListingStatus::PendingReview,
            'submitted_at' => now(),
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => ListingStatus::Approved,
            'submitted_at' => now()->subDay(),
            'reviewed_at' => now(),
            'reviewed_by' => User::factory(),
        ]);
    }

    public function rejected(string $reason = 'The photos do not show the unit.'): static
    {
        return $this->state(fn () => [
            'status' => ListingStatus::Rejected,
            'submitted_at' => now()->subDay(),
            'reviewed_at' => now(),
            'reviewed_by' => User::factory(),
            'rejection_reason' => $reason,
        ]);
    }
}
