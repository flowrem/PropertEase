<?php

namespace App\Actions\Reservations;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelReservation
{
    /**
     * Cancel an approved reservation that was never fulfilled: release the
     * held slot, disable the tenant account created for it and take that
     * account off the team's roster. Any refund is handled outside the system.
     *
     * @throws ValidationException
     */
    public function handle(Reservation $reservation, string $reason): void
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['cancelReason' => __('Give a reason for cancelling this reservation.')]);
        }

        DB::transaction(function () use ($reservation, $reason) {
            $locked = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);

            if ($locked->status !== ReservationStatus::Approved) {
                throw ValidationException::withMessages(['reservation' => __('Only an approved reservation can be cancelled.')]);
            }

            $locked->forceFill([
                'status' => ReservationStatus::Cancelled,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ])->save();

            $tenant = $locked->tenant_user_id ? User::query()->lockForUpdate()->find($locked->tenant_user_id) : null;

            if ($tenant) {
                $tenant->forceFill(['disabled_at' => now(), 'remember_token' => null])->save();
                $locked->team->memberships()->where('user_id', $tenant->id)->delete();
            }
        });
    }
}
