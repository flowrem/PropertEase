<?php

namespace App\Models;

use App\Enums\LeaseStatus;
use App\Enums\UnitStatus;
use Database\Factories\UnitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $property_id
 * @property string $unit_number
 * @property string|null $floor_level
 * @property int $bedrooms
 * @property int $bathrooms
 * @property UnitStatus $status
 * @property bool $allows_multiple_tenants
 * @property int|null $tenant_limit
 * @property string|null $price
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Property $property
 * @property-read UnitListing|null $listing
 * @property-read Collection<int, Lease> $leases
 * @property-read Collection<int, Lease> $activeLeases
 * @property-read int|null $active_leases_count
 * @property-read Collection<int, Concern> $concerns
 */
#[Fillable(['property_id', 'unit_number', 'floor_level', 'bedrooms', 'bathrooms', 'status', 'allows_multiple_tenants', 'tenant_limit', 'price'])]
class Unit extends Model
{
    /** @use HasFactory<UnitFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return HasOne<UnitListing, $this>
     */
    public function listing(): HasOne
    {
        return $this->hasOne(UnitListing::class);
    }

    /**
     * @return HasMany<Lease, $this>
     */
    public function leases(): HasMany
    {
        return $this->hasMany(Lease::class);
    }

    /**
     * Get every active lease on this unit (there can be more than one when
     * the unit allows multiple tenants).
     *
     * @return HasMany<Lease, $this>
     */
    public function activeLeases(): HasMany
    {
        return $this->hasMany(Lease::class)->where('status', LeaseStatus::Active->value);
    }

    /**
     * @return HasManyThrough<Concern, Lease, $this>
     */
    public function concerns(): HasManyThrough
    {
        return $this->hasManyThrough(Concern::class, Lease::class);
    }

    /**
     * Count this unit's active leases, reusing an eager-loaded count or
     * relation before falling back to a query.
     */
    public function activeLeaseCount(): int
    {
        if ($this->active_leases_count !== null) {
            return $this->active_leases_count;
        }

        if ($this->relationLoaded('activeLeases')) {
            return $this->activeLeases->count();
        }

        return $this->activeLeases()->count();
    }

    /**
     * Determine whether this unit currently has room for another tenant.
     */
    public function hasRoomForAnotherTenant(): bool
    {
        if ($this->status === UnitStatus::Vacant) {
            return true;
        }

        if (! $this->allows_multiple_tenants || $this->tenant_limit === null) {
            return false;
        }

        return $this->activeLeaseCount() < $this->tenant_limit;
    }

    /**
     * Limit the query to units that currently have room for another tenant.
     * Mirrors hasRoomForAnotherTenant() so both stay the single rule for capacity.
     *
     * @param  Builder<Unit>  $query
     */
    public function scopeHasRoom(Builder $query): void
    {
        $query->where(fn (Builder $room) => $room
            ->where('units.status', UnitStatus::Vacant->value)
            ->orWhere(fn (Builder $shared) => $shared
                ->where('units.allows_multiple_tenants', true)
                ->whereNotNull('units.tenant_limit')
                ->whereRaw(
                    '(select count(*) from leases where leases.unit_id = units.id and leases.status = ?) < units.tenant_limit',
                    [LeaseStatus::Active->value],
                )));
    }

    /**
     * How many more tenants this unit can take right now.
     */
    public function slotsAvailable(): int
    {
        $capacity = $this->allows_multiple_tenants && $this->tenant_limit !== null
            ? $this->tenant_limit
            : 1;

        return max(0, $capacity - $this->activeLeaseCount());
    }

    /**
     * Get each tenant's equal share of this unit's price, split across the
     * given number of tenants (defaults to the unit's current active lease count).
     */
    public function rentSharePerTenant(?int $tenantCount = null): float
    {
        $tenantCount ??= $this->activeLeaseCount();

        return (float) ($this->price ?? 0) / max($tenantCount, 1);
    }

    /**
     * Record every active tenant's equal share of this unit's price as their
     * new rent. Call after the price changes or a tenant is added or removed.
     *
     * A lease's very first rent record takes effect immediately, since there's
     * no billing cycle yet to protect. A lease that already has rent history
     * gets the new amount effective on its own next due day, so nothing it's
     * already mid-cycle on changes retroactively.
     */
    public function splitRentAmongActiveTenants(): void
    {
        $activeLeases = $this->activeLeases()->get();

        if ($activeLeases->isEmpty()) {
            return;
        }

        $share = $this->rentSharePerTenant($activeLeases->count());

        foreach ($activeLeases as $lease) {
            $lease->rents()->create([
                'amount' => $share,
                'effective_date' => $lease->rents()->exists()
                    ? $lease->nextRentEffectiveDate()
                    : today(),
            ]);
        }
    }

    /**
     * Recompute occupancy after a lease on this unit ends: re-split rent
     * among whoever remains active, or fall back to vacant if no one is left.
     */
    public function resyncAfterLeaseEnded(): void
    {
        if ($this->activeLeases()->exists()) {
            $this->splitRentAmongActiveTenants();

            return;
        }

        $this->update(['status' => UnitStatus::Vacant]);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => UnitStatus::class,
            'allows_multiple_tenants' => 'boolean',
            'price' => 'decimal:2',
        ];
    }
}
