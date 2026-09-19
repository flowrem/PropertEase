<?php

namespace Database\Factories;

use App\Models\Concern;
use App\Models\ConcernUpdate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConcernUpdate>
 */
class ConcernUpdateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'concern_id' => Concern::factory(),
            'author_id' => User::factory(),
            'message' => fake()->sentence(),
            'new_status' => null,
        ];
    }
}
