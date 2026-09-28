<?php

namespace Database\Factories;

use App\Models\Lease;
use App\Models\LeaseContract;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeaseContract>
 */
class LeaseContractFactory extends Factory
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
            'body_html' => '<p>Contract text.</p>',
            'terms' => ['rent' => 5000],
            'generated_at' => now(),
        ];
    }

    public function accepted(): static
    {
        return $this->state(fn () => ['tenant_accepted_at' => now(), 'tenant_accepted_ip' => '127.0.0.1']);
    }
}
