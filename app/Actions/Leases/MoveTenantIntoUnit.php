<?php

namespace App\Actions\Leases;

use App\Actions\Contracts\GenerateLeaseContract;
use App\Enums\BillingTiming;
use App\Enums\LeaseStatus;
use App\Enums\ReservationStatus;
use App\Enums\StayType;
use App\Enums\UnitStatus;
use App\Models\Lease;
use App\Models\Reservation;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MoveTenantIntoUnit
{
    public function __construct(private GenerateLeaseContract $generateLeaseContract) {}

    /**
     * Start a lease for the tenant on the unit: end the lease they have on
     * the same team (a move), fulfil the reservation holding a slot for
     * them, claim the unit's move-in check, re-split rent in both units and
     * make the new lease's contract. The unit must have room and a clean
     * move-in check, unless the caller already allowed going ahead without
     * one and passes the reason. Callers check who may do this.
     *
     * @throws ValidationException
     */
    public function handle(
        User $tenant,
        Unit $unit,
        int $dueDay,
        BillingTiming $billingTiming,
        ?StayType $stayType,
        User $movedBy,
        ?string $overrideReason = null,
        bool $holdsSlotOnUnit = false,
    ): Lease {
        if (! $unit->hasRoomForAnotherTenant(excludingOwnHold: $holdsSlotOnUnit)) {
            throw ValidationException::withMessages(['unit_id' => __('This unit has no room left.')]);
        }

        $moveInCheck = $unit->pendingMoveInCheck();
        $moveInCheckPasses = $moveInCheck !== null && $moveInCheck->blockingItemCount() === 0;
        $overrideReason = $overrideReason !== null ? trim($overrideReason) : null;

        if (! $moveInCheckPasses && ! $overrideReason) {
            throw ValidationException::withMessages(['unit_id' => $moveInCheck
                ? __('This unit\'s move-in check found items not working or missing.')
                : __('Record a move-in check for this unit first.')]);
        }

        $teamId = $unit->property->team_id;

        $lease = DB::transaction(function () use ($tenant, $unit, $teamId, $dueDay, $billingTiming, $stayType, $moveInCheck, $moveInCheckPasses, $overrideReason): Lease {
            $tenant->leases()
                ->where('status', LeaseStatus::Active->value)
                ->whereHas('unit.property', fn ($properties) => $properties->where('team_id', $teamId))
                ->get()
                ->each(fn (Lease $currentLease) => $currentLease->end(LeaseStatus::Ended));

            // The reservation is done once the tenant moves in anywhere on this
            // team, so a move into a different unit also releases the held slot.
            Reservation::query()
                ->where('team_id', $teamId)
                ->where('tenant_user_id', $tenant->id)
                ->where('status', ReservationStatus::Confirmed->value)
                ->get()
                ->each(fn (Reservation $reservation) => $reservation->forceFill(['status' => ReservationStatus::Fulfilled])->save());

            $lease = $unit->leases()->create([
                'tenant_id' => $tenant->id,
                'start_date' => now(),
                'due_day' => $dueDay,
                'billing_timing' => $billingTiming,
                'stay_type' => $stayType,
                'status' => LeaseStatus::Active,
                'move_in_override_reason' => $moveInCheckPasses ? null : $overrideReason,
            ]);

            $moveInCheck?->claimFor($lease);

            $unit->update(['status' => UnitStatus::Occupied]);
            $unit->splitRentAmongActiveTenants();

            return $lease;
        });

        $this->generateLeaseContract->handle($lease->fresh(), $movedBy);

        return $lease;
    }
}
