<?php

namespace Database\Factories;

use App\Models\Lease;
use App\Models\Service;
use App\Models\ServiceSubscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceSubscription>
 */
class ServiceSubscriptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lease_id' => Lease::factory(),
            'service_id' => Service::factory(),
            'start_date' => fake()->dateTimeBetween('-1 year', 'now'),
            'end_date' => null,
        ];
    }
}
