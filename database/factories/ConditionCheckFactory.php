<?php

namespace Database\Factories;

use App\Enums\ConditionCheckKind;
use App\Models\ConditionCheck;
use App\Models\Lease;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConditionCheck>
 */
class ConditionCheckFactory extends Factory
{
    /**
     * Define the model's default state: a move-in check no lease has
     * claimed yet, recorded just now.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'unit_id' => Unit::factory(),
            'kind' => ConditionCheckKind::MoveIn,
            'checked_at' => now(),
        ];
    }

    public function kind(ConditionCheckKind $kind): static
    {
        return $this->state(fn () => ['kind' => $kind]);
    }

    /**
     * A move-in check already claimed by the given lease.
     */
    public function claimedBy(Lease $lease): static
    {
        return $this->state(fn () => ['unit_id' => $lease->unit_id])
            ->afterCreating(fn (ConditionCheck $check) => $check->claimFor($lease));
    }
}
