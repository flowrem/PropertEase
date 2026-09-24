<?php

namespace App\Policies;

use App\Models\Reservation;
use App\Models\User;

class ReservationPolicy
{
    /**
     * Landlord, Manager and Staff can see their own team's reservations,
     * including the ID and payment proof. Tenants and outsiders cannot.
     */
    public function view(User $user, Reservation $reservation): bool
    {
        $team = $reservation->team;

        return $user->belongsToTeam($team) && $user->isLandlordOn($team);
    }

    /**
     * Only Landlord and Manager can approve, reject or resend login details.
     */
    public function review(User $user, Reservation $reservation): bool
    {
        return $user->canManageListingsOn($reservation->team);
    }
}
