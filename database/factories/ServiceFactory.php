<?php

namespace Database\Factories;

use App\Enums\ServiceBillingType;
use App\Models\Service;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
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
            'name' => fake()->randomElement(['Water', 'Electricity', 'Internet', 'Garbage Collection']),
            'billing_type' => fake()->randomElement(ServiceBillingType::cases()),
            'price' => fake()->numberBetween(100, 2000),
            'description' => fake()->sentence(),
        ];
    }
}
