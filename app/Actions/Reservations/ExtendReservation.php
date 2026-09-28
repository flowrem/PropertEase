<?php

namespace App\Actions\Reservations;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Models\Unit;
use App\Notifications\ReservationExtended;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class ExtendReservation
{
    /**
     * Give the applicant more days to pay the downpayment, counted from the
     * current deadline (or from now, when it has already passed), and email
     * them the new one. A reservation whose deadline passed has stopped
     * holding the unit, so it can only be extended while the unit still
     * has room.
     *
     * @throws ValidationException
     */
    public function handle(Reservation $reservation, int $days): void
    {
        $limits = config('occuplace.reservations.hold_days');

        if ($days < $limits['min'] || $days > $limits['max']) {
            throw ValidationException::withMessages(['extendDays' => __('Extend by :min to :max days.', $limits)]);
        }

        DB::transaction(function () use ($reservation, $days) {
            $locked = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
            $unit = Unit::query()->lockForUpdate()->findOrFail($locked->unit_id);

            if ($locked->status !== ReservationStatus::Reserved || $locked->hasDownpaymentSent()) {
                throw ValidationException::withMessages(['reservation' => __('Only a reservation still waiting for its downpayment can be extended.')]);
            }

            if ($locked->isPastDeadline() && ! $unit->hasRoomForAnotherTenant()) {
                throw ValidationException::withMessages(['reservation' => __('The deadline passed and the unit has no room left, so this reservation cannot be extended.')]);
            }

            $deadline = $locked->expires_at;
            $from = $deadline !== null && $deadline->isFuture() ? $deadline : now();

            $locked->forceFill(['expires_at' => $from->addDays($days)])->save();

            $reservation->setRawAttributes($locked->getAttributes(), true);
        });

        Notification::route('mail', $reservation->email)->notify(new ReservationExtended($reservation));
    }
}
