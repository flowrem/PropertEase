<?php

namespace App\Policies;

use App\Enums\TransferStatus;
use App\Models\TransferRequest;
use App\Models\User;

class TransferRequestPolicy
{
    /**
     * The tenant who asked, and anyone on the landlord's side of the team.
     */
    public function view(User $user, TransferRequest $transfer): bool
    {
        return $transfer->tenant_id === $user->id
            || ($user->belongsToTeam($transfer->team) && $user->isLandlordOn($transfer->team));
    }

    /**
     * Only the landlord and managers approve, reject, cancel or complete a
     * transfer; staff can see requests but not decide them.
     */
    public function review(User $user, TransferRequest $transfer): bool
    {
        return $user->belongsToTeam($transfer->team) && $user->canManageListingsOn($transfer->team);
    }

    /**
     * The tenant can withdraw their own request while it is still pending.
     */
    public function withdraw(User $user, TransferRequest $transfer): bool
    {
        return $transfer->tenant_id === $user->id && $transfer->status === TransferStatus::Pending;
    }
}
