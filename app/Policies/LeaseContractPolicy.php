<?php

namespace App\Policies;

use App\Enums\LeaseStatus;
use App\Models\Lease;
use App\Models\LeaseContract;
use App\Models\User;

class LeaseContractPolicy
{
    /**
     * The lease's own tenant, and anyone on the landlord's side of its team,
     * can read and print the contract.
     */
    public function view(User $user, LeaseContract $contract): bool
    {
        $lease = $contract->lease;
        $team = $lease->unit->property->team;

        return $lease->tenant_id === $user->id
            || ($user->belongsToTeam($team) && $user->isLandlordOn($team));
    }

    /**
     * Only the lease's own tenant agrees to it, and only once.
     */
    public function accept(User $user, LeaseContract $contract): bool
    {
        return $contract->lease->tenant_id === $user->id && ! $contract->isAccepted();
    }

    /**
     * The landlord and managers make a contract for an active lease, or
     * make it again from the current terms until the tenant has agreed.
     */
    public function generate(User $user, Lease $lease): bool
    {
        $team = $lease->unit->property->team;

        return $user->belongsToTeam($team)
            && $user->canManageListingsOn($team)
            && $lease->status === LeaseStatus::Active
            && ! $lease->contract?->isAccepted();
    }
}
