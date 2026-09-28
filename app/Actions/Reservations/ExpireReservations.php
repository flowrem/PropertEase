<?php

namespace App\Actions\Reservations;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Notifications\ReservationExpired;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class ExpireReservations
{
    /**
     * Mark every reserved reservation whose deadline passed with no
     * downpayment sent as expired, and email each applicant. The slot was
     * already free the moment the deadline passed (see
     * Reservation::scopeHoldingSlot()); this records it and tells them.
     * Safe to run any number of times.
     *
     * @return int How many reservations were expired.
     */
    public function handle(): int
    {
        $expired = 0;

        Reservation::query()->overdue()->pluck('id')->each(function (int $reservationId) use (&$expired) {
            $reservation = DB::transaction(function () use ($reservationId): ?Reservation {
                $locked = Reservation::query()->lockForUpdate()->find($reservationId);

                if (! $locked?->isOverdue()) {
                    return null;
                }

                $locked->forceFill(['status' => ReservationStatus::Expired, 'expired_at' => now()])->save();

                return $locked;
            });

            if ($reservation) {
                Notification::route('mail', $reservation->email)->notify(new ReservationExpired($reservation));
                $expired++;
            }
        });

        return $expired;
    }
}
