<?php

namespace App\Policies;

use App\Models\PaymentChannel;
use App\Models\Team;
use App\Models\User;

class PaymentChannelPolicy
{
    /**
     * Staff can view their team's channels but not change them.
     */
    public function view(User $user, PaymentChannel $channel): bool
    {
        return $user->belongsToTeam($channel->team) && $user->isLandlordOn($channel->team);
    }

    public function create(User $user, Team $team): bool
    {
        return $user->canManageListingsOn($team);
    }

    public function update(User $user, PaymentChannel $channel): bool
    {
        return $user->canManageListingsOn($channel->team);
    }
}
