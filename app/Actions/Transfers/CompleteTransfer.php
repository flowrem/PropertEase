<?php

namespace App\Actions\Transfers;

use App\Actions\Leases\MoveTenantIntoUnit;
use App\Enums\ConditionCheckKind;
use App\Enums\LeaseStatus;
use App\Enums\TransferStatus;
use App\Models\ConditionCheck;
use App\Models\Lease;
use App\Models\TransferRequest;
use App\Models\User;
use App\Notifications\TransferUpdated;
use Illuminate\Validation\ValidationException;

class CompleteTransfer
{
    public function __construct(private MoveTenantIntoUnit $moveTenantIntoUnit) {}

    /**
     * Move the tenant on or after the move date. The old unit needs a
     * move-out check recorded since the transfer was approved, which is
     * attached to the old lease; the new unit needs a clean move-in check
     * or the landlord's reason for going ahead (checked by the caller). The
     * move itself is the same as any move on the Tenants page: the old lease
     * ends, a new one starts on the same billing terms, rent is re-split in
     * both units and the new contract is made.
     *
     * @throws ValidationException
     */
    public function handle(TransferRequest $transfer, User $completedBy, ?string $overrideReason = null): Lease
    {
        $transfer->loadMissing(['lease', 'toUnit.property', 'tenant']);

        $this->ensure($transfer->status === TransferStatus::Approved, __('Only an approved transfer can be completed.'));
        $this->ensure($transfer->isDueToMove(), __('The move date hasn\'t come yet.'));
        $this->ensure($transfer->lease->status === LeaseStatus::Active, __('The tenant\'s lease has already ended.'));

        $moveOutCheck = $this->moveOutCheckFor($transfer);

        $this->ensure($moveOutCheck !== null, __('Record a move-out check of the tenant\'s current unit first.'));

        $oldLease = $transfer->lease;

        $newLease = $this->moveTenantIntoUnit->handle(
            $transfer->tenant,
            $transfer->toUnit,
            $oldLease->due_day,
            $oldLease->billing_timing,
            $oldLease->stay_type,
            $completedBy,
            $overrideReason,
            holdsSlotOnUnit: true,
        );

        $moveOutCheck->claimFor($oldLease);

        $transfer->forceFill([
            'status' => TransferStatus::Completed,
            'new_lease_id' => $newLease->id,
            'completed_at' => now(),
        ])->save();

        $transfer->tenant->notify(new TransferUpdated($transfer));

        return $newLease;
    }

    /**
     * The newest move-out check of the tenant's current unit recorded since
     * the transfer was approved and not yet attached to a lease.
     */
    public function moveOutCheckFor(TransferRequest $transfer): ?ConditionCheck
    {
        return ConditionCheck::query()
            ->where('unit_id', $transfer->from_unit_id)
            ->where('kind', ConditionCheckKind::MoveOut->value)
            ->whereNull('lease_id')
            ->where('checked_at', '>=', ($transfer->reviewed_at ?? $transfer->created_at)?->startOfDay())
            ->latest('checked_at')
            ->first();
    }

    /**
     * @throws ValidationException
     */
    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['transfer' => $message]);
        }
    }
}
