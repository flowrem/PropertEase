<?php

namespace App\Actions\Reservations;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Models\User;
use App\Notifications\ReservationRejected;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class RejectReservation
{
    /**
     * Reject a pending reservation and email the applicant the reason.
     * Any refund of a downpayment is handled outside the system.
     *
     * @throws ValidationException
     */
    public function handle(Reservation $reservation, User $reviewer, string $reason): void
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => __('Give a reason for rejecting this reservation.')]);
        }

        DB::transaction(function () use ($reservation, $reviewer, $reason) {
            $locked = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);

            if ($locked->status !== ReservationStatus::Pending) {
                throw ValidationException::withMessages(['reservation' => __('This reservation is no longer pending.')]);
            }

            $locked->forceFill([
                'status' => ReservationStatus::Rejected,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
            ])->save();

            $reservation->setRawAttributes($locked->getAttributes(), true);
        });

        Notification::route('mail', $reservation->email)->notify(new ReservationRejected($reservation));
    }
}
