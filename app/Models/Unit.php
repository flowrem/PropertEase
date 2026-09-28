<?php

namespace App\Models;

use App\Enums\ConditionCheckKind;
use App\Enums\LeaseStatus;
use App\Enums\PropertyType;
use App\Enums\ReservationStatus;
use App\Enums\UnitStatus;
use Carbon\CarbonImmutable;
use Closure;
use Database\Factories\UnitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int $property_id
 * @property string $unit_number
 * @property string|null $floor_level
 * @property int $bedrooms
 * @property int $bathrooms
 * @property string|null $floor_area_sqm
 * @property UnitStatus $status
 * @property bool $allows_multiple_tenants
 * @property int|null $tenant_limit
 * @property string|null $price
 * @property string|null $photo_path
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Property $property
 * @property-read UnitListing|null $listing
 * @property-read Collection<int, Amenity> $amenities
 * @property-read Collection<int, Lease> $leases
 * @property-read Collection<int, Lease> $activeLeases
 * @property-read int|null $active_leases_count
 * @property-read Collection<int, Reservation> $reservations
 * @property-read Collection<int, Reservation> $heldReservations
 * @property-read int|null $held_reservations_count
 * @property-read int|string|null $bed_spaces
 * @property-read Collection<int, Concern> $concerns
 * @property-read Collection<int, UnitItem> $items
 * @property-read Collection<int, ConditionCheck> $conditionChecks
 */
#[Fillable(['property_id', 'unit_number', 'floor_level', 'bedrooms', 'bathrooms', 'floor_area_sqm', 'status', 'allows_multiple_tenants', 'tenant_limit', 'price'])]
class Unit extends Model
{
    /** @use HasFactory<UnitFactory> */
    use HasFactory;

    /**
     * How many people a unit's beds sleep in total, as a correlated
     * subquery on `units`. Shared by withBedSpaces() and scopeHasRoom().
     */
    private const BED_SPACES_SQL = '(select coalesce(sum(amenity_unit.quantity * amenities.sleeps), 0)'
        .' from amenity_unit inner join amenities on amenities.id = amenity_unit.amenity_id'
        .' where amenity_unit.unit_id = units.id)';

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
     * The landlord's photo of the unit, or null to show the placeholder.
     */
    public function photoUrl(): ?string
    {
        return $this->photo_path
            ? Storage::disk(config('filesystems.media_disk'))->url($this->photo_path)
            : null;
    }

    /**
     * What the unit comes with, and how many of each.
     *
     * @return BelongsToMany<Amenity, $this, Pivot, 'pivot'>
     */
    public function amenities(): BelongsToMany
    {
        return $this->belongsToMany(Amenity::class)->withPivot('quantity')->withTimestamps();
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
     * Everything in the unit that gets checked, including removed items.
     *
     * @return HasMany<UnitItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(UnitItem::class);
    }

    /**
     * @return HasMany<ConditionCheck, $this>
     */
    public function conditionChecks(): HasMany
    {
        return $this->hasMany(ConditionCheck::class);
    }

    /**
     * The move-in check the next tenant assigned here would claim: the
     * newest one not yet claimed by a lease and recorded no earlier than the
     * day the last tenant moved out, since a check from before then no
     * longer shows the unit's condition. Null when there is none.
     */
    public function pendingMoveInCheck(): ?ConditionCheck
    {
        $lastMoveOut = $this->leases()->where('status', '!=', LeaseStatus::Active->value)->max('end_date');

        return $this->conditionChecks()
            ->where('kind', ConditionCheckKind::MoveIn->value)
            ->whereNull('lease_id')
            ->when($lastMoveOut, fn (Builder $checks) => $checks->where('checked_at', '>=', CarbonImmutable::parse($lastMoveOut)->startOfDay()))
            ->latest('checked_at')
            ->latest('id')
            ->first();
    }

    /**
     * @return HasMany<Reservation, $this>
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    /**
     * Approved reservations that are holding a slot until they are fulfilled,
     * cancelled or rejected.
     *
     * @return HasMany<Reservation, $this>
     */
    public function heldReservations(): HasMany
    {
        return $this->hasMany(Reservation::class)->where('status', ReservationStatus::Approved->value);
    }

    /**
     * Count the slots held by approved reservations, reusing an eager-loaded
     * count before falling back to a query.
     */
    public function heldReservationCount(): int
    {
        return $this->held_reservations_count ?? $this->heldReservations()->count();
    }

    /**
     * How many tenants this unit can hold in total: the landlord's chosen
     * limit, never more than its floor area allows.
     */
    public function capacity(): int
    {
        $chosen = $this->allows_multiple_tenants && $this->tenant_limit !== null
            ? $this->tenant_limit
            : 1;

        $maxCapacity = $this->maxCapacity();

        return $maxCapacity === null ? $chosen : min($chosen, $maxCapacity);
    }

    /**
     * The most tenants this unit's floor area, beds and bedrooms allow, or
     * null for an older unit whose floor area has not been entered yet.
     */
    public function maxCapacity(): ?int
    {
        if ($this->floor_area_sqm === null) {
            return null;
        }

        return self::maxCapacityFor((float) $this->floor_area_sqm, $this->property->type, $this->bedrooms, $this->bedSpaces());
    }

    /**
     * The most tenants a unit can hold, never more than its floor area fits:
     *
     * - with beds listed, as many as those beds sleep (2 double decks and a
     *   single bed sleep 5), since each tenant needs somewhere to sleep;
     * - with no beds listed (an unfurnished unit, where tenants bring their
     *   own), an estimate from its bedrooms;
     * - a studio or bedspace with neither, whatever its floor area fits.
     */
    public static function maxCapacityFor(float $floorArea, PropertyType $type, int $bedrooms, int $bedSpaces = 0): int
    {
        $tenantsThatFit = self::tenantsThatFitIn($floorArea, $type);

        if ($bedSpaces > 0) {
            $tenantsThatFit = min($tenantsThatFit, $bedSpaces);
        } elseif ($bedrooms > 0) {
            $tenantsThatFit = min($tenantsThatFit, $bedrooms * $type->tenantsPerBedroom());
        }

        return max(1, $tenantsThatFit);
    }

    /**
     * The most tenants a floor area fits by its area per tenant alone, never
     * more than the configured maximum capacity. Beds may not sleep more.
     */
    public static function tenantsThatFitIn(float $floorArea, PropertyType $type): int
    {
        return min(
            (int) config('occuplace.units.max_capacity'),
            (int) floor($floorArea / $type->areaPerTenant() + 1e-9),
        );
    }

    /**
     * How much floor, in m², the beds of a unit may cover: the configured
     * share of what is left once the common area and bathrooms (at least
     * one, even when shared) are set aside. The rest stays free for walking,
     * cabinets and doors.
     */
    public static function bedFloorSpaceFor(float $floorArea, int $bedrooms, int $bathrooms): float
    {
        $sleepingArea = $floorArea
            - self::commonAreaFor($bedrooms)
            - max($bathrooms, 1) * (float) config('occuplace.units.minimum_bathroom_area');

        return max(0.0, $sleepingArea) * (float) config('occuplace.units.bed_floor_coverage');
    }

    /**
     * How many people this unit's beds sleep in total, reusing a value
     * loaded by withBedSpaces() before falling back to a query.
     */
    public function bedSpaces(): int
    {
        if ($this->bed_spaces !== null) {
            return (int) $this->bed_spaces;
        }

        return (int) $this->amenities()->sum(DB::raw('amenity_unit.quantity * amenities.sleeps'));
    }

    /**
     * Load each unit's bed total alongside it, so listing many units and
     * their capacity doesn't run a query per unit.
     *
     * @param  Builder<Unit>  $query
     */
    public function scopeWithBedSpaces(Builder $query): void
    {
        if ($query->getQuery()->columns === null) {
            $query->select('units.*');
        }

        $query->selectRaw(self::BED_SPACES_SQL.' as bed_spaces');
    }

    /**
     * The smallest floor area that fits the given rooms. Always accounts for
     * at least one bathroom, even when "bathrooms" is 0 (shared), and for a
     * common kitchen and living area once the unit has bedrooms, to match
     * maxBedroomsFor(): bedrooms alone should never be allowed to claim a
     * floor area that leaves no room for a bathroom or a kitchen.
     */
    public static function minimumFloorAreaFor(int $bedrooms, int $bathrooms): float
    {
        return max(
            (float) config('occuplace.units.floor_area.min'),
            $bedrooms * (float) config('occuplace.units.minimum_bedroom_area')
                + max($bathrooms, 1) * (float) config('occuplace.units.minimum_bathroom_area')
                + self::commonAreaFor($bedrooms),
        );
    }

    /**
     * The most bedrooms a unit of the given floor area can have, once the
     * given number of bathrooms and the common area are accounted for.
     * Always reserves room for at least one bathroom, even when "bathrooms"
     * is 0 (shared), so bedrooms alone can never claim the entire floor area
     * and leave a unit with nowhere to put a bathroom or a kitchen.
     */
    public static function maxBedroomsFor(float $floorArea, int $bathrooms): int
    {
        return self::roomsThatFit(
            $floorArea
                - max($bathrooms, 1) * (float) config('occuplace.units.minimum_bathroom_area')
                - (float) config('occuplace.units.common_area'),
            (float) config('occuplace.units.minimum_bedroom_area'),
            (int) config('occuplace.units.bedrooms.max'),
        );
    }

    /**
     * The most bathrooms a unit of the given floor area can have, once the
     * given number of bedrooms and the common area are accounted for. Never
     * more than one bathroom per bedroom plus a shared one, since a unit's
     * leftover floor area alone would otherwise "fit" far more bathrooms
     * than any real rental has: a bathroom only needs a small minimum area,
     * so a modest unit with few bedrooms would appear to have room for
     * several of them.
     */
    public static function maxBathroomsFor(float $floorArea, int $bedrooms): int
    {
        return self::roomsThatFit(
            $floorArea
                - $bedrooms * (float) config('occuplace.units.minimum_bedroom_area')
                - self::commonAreaFor($bedrooms),
            (float) config('occuplace.units.minimum_bathroom_area'),
            min($bedrooms + 1, (int) config('occuplace.units.bathrooms.max')),
        );
    }

    /**
     * The floor area kept for a kitchen and living space. A studio (0
     * bedrooms) is one open room, so it needs none set aside.
     */
    private static function commonAreaFor(int $bedrooms): float
    {
        return $bedrooms > 0 ? (float) config('occuplace.units.common_area') : 0.0;
    }

    /**
     * How many rooms of the given minimum area fit in the remaining floor
     * area, clamped between 0 and the configured ceiling for that room type.
     */
    private static function roomsThatFit(float $remainingArea, float $minimumRoomArea, int $ceiling): int
    {
        $fits = (int) floor($remainingArea / $minimumRoomArea + 1e-9);

        return max(0, min($ceiling, $fits));
    }

    /**
     * Format a floor area for display, dropping a trailing ".0" ("24", "24.5").
     */
    public static function formatFloorArea(float $floorArea): string
    {
        return number_format($floorArea, floor($floorArea) === $floorArea ? 0 : 1);
    }

    /**
     * The floor levels a landlord can pick from, lowest first.
     *
     * @return array<int, string>
     */
    public static function floorLevelOptions(): array
    {
        $levels = ['Basement', 'Ground floor'];

        foreach (range(2, (int) config('occuplace.units.highest_floor')) as $floor) {
            $suffix = match (true) {
                in_array($floor % 100, [11, 12, 13], true) => 'th',
                $floor % 10 === 1 => 'st',
                $floor % 10 === 2 => 'nd',
                $floor % 10 === 3 => 'rd',
                default => 'th',
            };

            $levels[] = "{$floor}{$suffix} floor";
        }

        return $levels;
    }

    /**
     * Determine whether the unit's physical details (floor level, bedrooms,
     * bathrooms and floor area) are set and can no longer be changed. Older
     * units stay open until their landlord completes them once.
     */
    public function hasLockedDetails(): bool
    {
        return $this->floor_area_sqm !== null;
    }

    /**
     * Count the slots taken by active tenants and approved reservations.
     */
    public function takenSlotCount(): int
    {
        return $this->activeLeaseCount() + $this->heldReservationCount();
    }

    /**
     * Determine whether the unit can be deleted: only if it was never used,
     * meaning no lease, no reservation and no listing sent for review.
     */
    public function canBeDeleted(): bool
    {
        return ! $this->leases()->exists()
            && ! $this->reservations()->exists()
            && ! $this->listing()->whereNotNull('submitted_at')->exists();
    }

    /**
     * Determine whether this unit currently has room for another tenant.
     * Slots held by approved reservations count as taken; pass true when the
     * person being assigned is the one holding one of them.
     */
    public function hasRoomForAnotherTenant(bool $excludingOwnHold = false): bool
    {
        if ($this->status !== UnitStatus::Vacant && ! ($this->allows_multiple_tenants && $this->tenant_limit !== null)) {
            return false;
        }

        $held = max(0, $this->heldReservationCount() - ($excludingOwnHold ? 1 : 0));

        return $this->capacity() - $this->activeLeaseCount() - $held > 0;
    }

    /**
     * Limit the query to units that currently have room for another tenant.
     * Mirrors hasRoomForAnotherTenant() so both stay the single rule for capacity.
     *
     * The floor-area cap is written as "area >= (taken + 1) * area per tenant"
     * instead of floor(area / area per tenant), so it reads the same on SQLite
     * and Postgres. Beds cap the unit when it has any; otherwise bedrooms do,
     * and a studio with neither is capped by floor area alone, as in
     * maxCapacityFor().
     *
     * @param  Builder<Unit>  $query
     */
    public function scopeHasRoom(Builder $query): void
    {
        $taken = '((select count(*) from leases where leases.unit_id = units.id and leases.status = ?)'
            .' + (select count(*) from reservations where reservations.unit_id = units.id and reservations.status = ?))';
        $takenBindings = [LeaseStatus::Active->value, ReservationStatus::Approved->value];

        [$areaPerTenant, $areaPerTenantBindings] = self::perPropertyTypeSql(
            fn (PropertyType $type): float => $type->areaPerTenant(),
        );
        [$tenantsPerBedroom, $tenantsPerBedroomBindings] = self::perPropertyTypeSql(
            fn (PropertyType $type): int => $type->tenantsPerBedroom(),
        );

        $query
            ->where(fn (Builder $room) => $room
                ->where('units.status', UnitStatus::Vacant->value)
                ->orWhere(fn (Builder $shared) => $shared
                    ->where('units.allows_multiple_tenants', true)
                    ->whereNotNull('units.tenant_limit')))
            ->whereRaw(
                '(case when units.allows_multiple_tenants and units.tenant_limit is not null then units.tenant_limit else 1 end)'
                ." > {$taken}",
                $takenBindings,
            )
            ->where(fn (Builder $room) => $room
                ->whereNull('units.floor_area_sqm')
                ->orWhereRaw("{$taken} < 1", $takenBindings)
                ->orWhereRaw(
                    "{$taken} < ? and units.floor_area_sqm >= ({$taken} + 1) * {$areaPerTenant}"
                    .' and (('.self::BED_SPACES_SQL.' > 0 and '.self::BED_SPACES_SQL." > {$taken})"
                    .' or ('.self::BED_SPACES_SQL." = 0 and (units.bedrooms = 0 or units.bedrooms * {$tenantsPerBedroom} > {$taken})))",
                    [
                        ...$takenBindings, (int) config('occuplace.units.max_capacity'),
                        ...$takenBindings, ...$areaPerTenantBindings,
                        ...$takenBindings,
                        ...$tenantsPerBedroomBindings, ...$takenBindings,
                    ],
                ));
    }

    /**
     * Build a SQL expression that picks a per-property-type value for the
     * unit's property, with its bindings.
     *
     * @param  Closure(PropertyType): (int|float)  $valueFor
     * @return array{0: literal-string, 1: array<int, int|float|string>}
     */
    private static function perPropertyTypeSql(Closure $valueFor): array
    {
        $sql = '(case (select properties.type from properties where properties.id = units.property_id)';
        $bindings = [];

        foreach (PropertyType::cases() as $type) {
            $sql .= ' when ? then cast(? as decimal(6, 2))';
            array_push($bindings, $type->value, $valueFor($type));
        }

        return [$sql.' end)', $bindings];
    }

    /**
     * How many more tenants this unit can take right now.
     */
    public function slotsAvailable(): int
    {
        return max(0, $this->capacity() - $this->takenSlotCount());
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
            'floor_area_sqm' => 'decimal:1',
        ];
    }
}
