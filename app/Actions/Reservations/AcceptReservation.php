<?php

namespace App\Actions\Reservations;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\ReservationAccepted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AcceptReservation
{
    /**
     * Accept a pending reservation: hold the unit for the team's number of
     * days and email the applicant the deadline and a link to pay the
     * downpayment. No account is created until the downpayment is confirmed.
     *
     * @throws ValidationException
     */
    public function handle(Reservation $reservation, User $reviewer): void
    {
        DB::transaction(function () use ($reservation, $reviewer) {
            $locked = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
            $unit = Unit::query()->lockForUpdate()->findOrFail($locked->unit_id);

            $this->ensure($locked->status === ReservationStatus::Pending, __('This reservation is no longer pending.'));
            $this->ensure(
                User::query()->where('username', Str::lower($locked->desired_username))->doesntExist(),
                __('That username has since been taken.'),
            );
            $this->ensure(
                User::query()->whereRaw('lower(email) = ?', [Str::lower($locked->email)])->doesntExist(),
                __('An account with that email already exists.'),
            );
            $this->ensure($unit->hasRoomForAnotherTenant(), __('This unit has no room left.'));

            $locked->forceFill([
                'status' => ReservationStatus::Reserved,
                'expires_at' => now()->addDays($locked->team->reservation_hold_days),
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ])->save();

            $reservation->setRawAttributes($locked->getAttributes(), true);
        });

        Notification::route('mail', $reservation->email)->notify(new ReservationAccepted($reservation));
    }

    /**
     * @throws ValidationException
     */
    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['reservation' => $message]);
        }
    }
}
