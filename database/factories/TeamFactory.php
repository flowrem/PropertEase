<?php

namespace Database\Factories;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Team>
 */
class TeamFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'is_personal' => false,
            'approved_at' => now(),
        ];
    }

    /**
     * Indicate that the landlord team has submitted an ID and is waiting for review.
     */
    public function awaitingApproval(): static
    {
        return $this->state(fn (array $attributes) => [
            'approved_at' => null,
            'verification_id_path' => 'landlord-ids/'.fake()->uuid().'.jpg',
            'verification_submitted_at' => now(),
        ]);
    }

    /**
     * Indicate that a Super Admin turned the landlord team down.
     */
    public function rejectedByAdmin(string $reason = 'The ID is not readable.'): static
    {
        return $this->awaitingApproval()->state(fn (array $attributes) => [
            'rejected_at' => now(),
            'rejection_reason' => $reason,
        ]);
    }

    /**
     * Indicate that the team is a personal team.
     */
    public function personal(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_personal' => true,
        ]);
    }

    /**
     * Indicate that the team has been deleted.
     */
    public function trashed(): static
    {
        return $this->state(fn (array $attributes) => [
            'deleted_at' => now(),
        ]);
    }
}
