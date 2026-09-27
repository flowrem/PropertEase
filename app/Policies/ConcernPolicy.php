<?php

namespace App\Policies;

use App\Models\Concern;
use App\Models\User;

class ConcernPolicy
{
    /**
     * The tenant who reported it, and anyone on the landlord's side of the
     * unit's team, can see a report and its photo.
     */
    public function view(User $user, Concern $concern): bool
    {
        if ($concern->lease->tenant_id === $user->id) {
            return true;
        }

        $team = $concern->lease->unit->property->team;

        return $user->belongsToTeam($team) && $user->isLandlordOn($team);
    }

    /**
     * Only the landlord and managers update or resolve a report; staff can
     * view it.
     */
    public function update(User $user, Concern $concern): bool
    {
        return $user->canManageListingsOn($concern->lease->unit->property->team);
    }
}
