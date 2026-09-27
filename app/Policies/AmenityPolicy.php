<?php

namespace App\Policies;

use App\Models\Amenity;
use App\Models\Team;
use App\Models\User;

class AmenityPolicy
{
    public function create(User $user, Team $team): bool
    {
        return $user->canManageListingsOn($team);
    }

    /**
     * Only a team's own amenities can be changed, and only by its landlord
     * or managers. Platform defaults belong to no team, so nobody can.
     */
    public function update(User $user, Amenity $amenity): bool
    {
        return $amenity->team !== null && $user->canManageListingsOn($amenity->team);
    }
}
