<?php

namespace App\Actions\Reservations;

use App\Enums\ReservationStatus;
use App\Enums\TeamRole;
use App\Models\Reservation;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\TenantAccountCreated;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ApproveReservation
{
    public const TEMPORARY_PASSWORD_HOURS = 72;

    /**
     * Approve a pending reservation: create the tenant account, put it on the
     * landlord's team as a Tenant, and hold the unit slot. The temporary
     * password is only ever handed to the queued, encrypted notification.
     *
     * @throws ValidationException
     */
    public function handle(Reservation $reservation, User $reviewer, bool $downpaymentConfirmed): User
    {
        $this->ensure($downpaymentConfirmed, __('Confirm that the downpayment arrived before approving.'));

        $temporaryPassword = Str::password(16);
        $expiresAt = now()->addHours(self::TEMPORARY_PASSWORD_HOURS);

        $tenant = DB::transaction(function () use ($reservation, $reviewer, $temporaryPassword, $expiresAt) {
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

            $tenant = (new User)->forceFill([
                'name' => $locked->fullName(),
                'email' => $locked->email,
                'username' => $locked->desired_username,
                'password' => $temporaryPassword,
                'must_change_password' => true,
                'temporary_password_expires_at' => $expiresAt,
                'current_team_id' => $locked->team_id,
            ]);
            $tenant->save();

            $locked->team->memberships()->create([
                'user_id' => $tenant->id,
                'role' => TeamRole::Tenant,
            ]);

            $locked->forceFill([
                'status' => ReservationStatus::Approved,
                'tenant_user_id' => $tenant->id,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'downpayment_confirmed_at' => now(),
                'downpayment_confirmed_by' => $reviewer->id,
            ])->save();

            return $tenant;
        });

        $reservation->refresh();

        $tenant->notify(new TenantAccountCreated(
            $tenant->username,
            $temporaryPassword,
            $expiresAt,
            $reservation->team->name,
        ));

        return $tenant;
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
