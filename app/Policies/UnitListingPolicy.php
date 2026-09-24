<?php

namespace App\Policies;

use App\Models\Unit;
use App\Models\UnitListing;
use App\Models\User;

class UnitListingPolicy
{
    /**
     * Landlord, Manager and Staff can view their own team's listings.
     */
    public function view(User $user, UnitListing $listing): bool
    {
        $team = $listing->team();

        return $user->belongsToTeam($team) && $user->isLandlordOn($team);
    }

    /**
     * Only Landlord and Manager can create a listing, and only for their own team's units.
     */
    public function create(User $user, Unit $unit): bool
    {
        return $user->canManageListingsOn($unit->property->team);
    }

    /**
     * Update, submit for review and unlist share the same rule.
     */
    public function update(User $user, UnitListing $listing): bool
    {
        return $user->canManageListingsOn($listing->team());
    }
}
