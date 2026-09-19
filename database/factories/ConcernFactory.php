<?php

namespace Database\Factories;

use App\Enums\ConcernCategory;
use App\Enums\ConcernPriority;
use App\Enums\ConcernStatus;
use App\Models\Concern;
use App\Models\Lease;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Concern>
 */
class ConcernFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $category = fake()->randomElement(ConcernCategory::cases());

        return [
            'lease_id' => Lease::factory(),
            'category' => $category,
            'title' => $category === ConcernCategory::Maintenance
                ? fake()->randomElement(['Leaking faucet', 'No water supply', 'Broken air conditioning', 'Internet outage'])
                : fake()->randomElement(['Noise complaint', 'Unresponsive landlord', 'Billing dispute']),
            'description' => fake()->paragraph(),
            'priority' => fake()->randomElement(ConcernPriority::cases()),
            'status' => fake()->randomElement(ConcernStatus::cases()),
            'photo_path' => null,
            'resolved_at' => null,
        ];
    }
}
