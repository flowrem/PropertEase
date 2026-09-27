<?php

namespace App\Policies;

use App\Models\Unit;
use App\Models\User;

class UnitPolicy
{
    /**
     * Anyone on the landlord's side of the unit's team can see its inventory
     * and checks; tenants cannot.
     */
    public function viewInventory(User $user, Unit $unit): bool
    {
        $team = $unit->property->team;

        return $user->belongsToTeam($team) && $user->isLandlordOn($team);
    }

    /**
     * Only the landlord and managers change what a unit's inventory lists,
     * and only they can let a tenant move in past a failed move-in check.
     */
    public function manageInventory(User $user, Unit $unit): bool
    {
        return $user->canManageListingsOn($unit->property->team);
    }

    /**
     * Inspections are hands-on work, so staff can record checks too.
     */
    public function recordConditionCheck(User $user, Unit $unit): bool
    {
        return $this->viewInventory($user, $unit);
    }
}
