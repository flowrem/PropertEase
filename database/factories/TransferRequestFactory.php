<?php

namespace Database\Factories;

use App\Enums\LeaseStatus;
use App\Enums\TransferStatus;
use App\Models\Lease;
use App\Models\TransferRequest;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TransferRequest>
 */
class TransferRequestFactory extends Factory
{
    /**
     * Define the model's default state. The lease, tenant and both units
     * follow from the lease, on the same property's team.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lease_id' => Lease::factory()->state(['status' => LeaseStatus::Active]),
            'tenant_id' => fn (array $attributes) => Lease::query()->whereKey($attributes['lease_id'])->firstOrFail()->tenant_id,
            'from_unit_id' => fn (array $attributes) => Lease::query()->whereKey($attributes['lease_id'])->firstOrFail()->unit_id,
            'to_unit_id' => fn (array $attributes) => Unit::factory()->for(Unit::query()->whereKey($attributes['from_unit_id'])->firstOrFail()->property),
            'team_id' => fn (array $attributes) => Unit::query()->whereKey($attributes['from_unit_id'])->firstOrFail()->property->team_id,
            'reason' => 'I need a unit closer to the stairs.',
            'preferred_date' => now()->addWeek()->toDateString(),
        ];
    }

    public function approved(?string $moveDate = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TransferStatus::Approved,
            'move_date' => $moveDate ?? $attributes['preferred_date'],
            'reviewed_at' => now(),
        ]);
    }
}
