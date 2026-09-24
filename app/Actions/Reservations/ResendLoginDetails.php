<?php

namespace App\Actions\Reservations;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Models\User;
use App\Notifications\TenantAccountCreated;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ResendLoginDetails
{
    /**
     * Replace the tenant's temporary password with a fresh one and email it.
     * Only allowed while the tenant has not yet chosen their own password,
     * so a working account can never be locked out by a resend.
     *
     * @throws ValidationException
     */
    public function handle(Reservation $reservation): void
    {
        $temporaryPassword = Str::password(16);
        $expiresAt = now()->addHours(ApproveReservation::TEMPORARY_PASSWORD_HOURS);

        $tenant = DB::transaction(function () use ($reservation, $temporaryPassword, $expiresAt) {
            $locked = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
            $tenant = $locked->tenant_user_id ? User::query()->lockForUpdate()->find($locked->tenant_user_id) : null;

            if ($locked->status !== ReservationStatus::Approved || ! $tenant?->must_change_password) {
                throw ValidationException::withMessages([
                    'reservation' => __('Login details can only be resent before the tenant has set their own password.'),
                ]);
            }

            $tenant->forceFill([
                'password' => $temporaryPassword,
                'temporary_password_expires_at' => $expiresAt,
                'remember_token' => null,
            ])->save();

            return $tenant;
        });

        $tenant->notify(new TenantAccountCreated(
            $tenant->username,
            $temporaryPassword,
            $expiresAt,
            $reservation->team->name,
        ));
    }
}
