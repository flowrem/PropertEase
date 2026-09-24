<?php

use App\Actions\Reservations\ApproveReservation;
use App\Actions\Reservations\CancelReservation;
use App\Actions\Reservations\RejectReservation;
use App\Actions\Reservations\ResendLoginDetails;
use App\Enums\ReservationStatus;
use App\Enums\TeamRole;
use App\Enums\UnitStatus;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\ReservationRejected;
use App\Notifications\TenantAccountCreated;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * @return array{0: User, 1: Reservation}
 */
function pendingReservation(array $overrides = []): array
{
    $landlord = User::factory()->create();
    $unit = Unit::factory()->for(Property::factory()->for($landlord->currentTeam))->create(['status' => UnitStatus::Vacant]);
    $reservation = Reservation::factory()->for($unit)->create($overrides);

    return [$landlord, $reservation];
}

test('approval is blocked without the downpayment confirmation', function () {
    Notification::fake();
    [$landlord, $reservation] = pendingReservation();

    expect(fn () => app(ApproveReservation::class)->handle($reservation, $landlord, false))
        ->toThrow(ValidationException::class);

    expect($reservation->fresh()->status)->toBe(ReservationStatus::Pending)
        ->and(User::where('email', $reservation->email)->exists())->toBeFalse();
    Notification::assertNothingSent();
});

test('approving creates exactly one tenant account, membership and hold', function () {
    Notification::fake();
    [$landlord, $reservation] = pendingReservation(['desired_username' => 'Juana_DC']);

    $tenant = app(ApproveReservation::class)->handle($reservation, $landlord, true);

    $fresh = $reservation->fresh();
    expect($fresh->status)->toBe(ReservationStatus::Approved)
        ->and($fresh->tenant_user_id)->toBe($tenant->id)
        ->and($fresh->reviewed_by)->toBe($landlord->id)
        ->and($fresh->downpayment_confirmed_at)->not->toBeNull()
        ->and($fresh->downpayment_confirmed_by)->toBe($landlord->id)
        ->and(User::where('email', $reservation->email)->count())->toBe(1);

    $tenant->refresh();
    expect($tenant->username)->toBe('juana_dc')
        ->and($tenant->must_change_password)->toBeTrue()
        ->and($tenant->temporary_password_expires_at->isFuture())->toBeTrue()
        ->and($tenant->current_team_id)->toBe($reservation->team_id)
        ->and($tenant->teamRole($landlord->currentTeam))->toBe(TeamRole::Tenant)
        ->and($reservation->unit->fresh()->hasRoomForAnotherTenant())->toBeFalse();
});

test('the temporary password is only sent by notification and stored hashed', function () {
    Notification::fake();
    [$landlord, $reservation] = pendingReservation();

    $tenant = app(ApproveReservation::class)->handle($reservation, $landlord, true);

    Notification::assertSentTo($tenant, TenantAccountCreated::class, function (TenantAccountCreated $notification) use ($tenant) {
        return strlen($notification->temporaryPassword) === 16
            && Hash::check($notification->temporaryPassword, $tenant->fresh()->password)
            && $notification->username === $tenant->username;
    });
});

test('the account notification is queued and encrypted', function () {
    $notification = new TenantAccountCreated('juana', 'secret-password', now()->addHours(72), 'Team');

    expect($notification)->toBeInstanceOf(ShouldQueue::class)
        ->toBeInstanceOf(ShouldBeEncrypted::class)
        ->and($notification->via(new User))->toBe(['mail']);
});

test('approving twice is blocked', function () {
    Notification::fake();
    [$landlord, $reservation] = pendingReservation();

    app(ApproveReservation::class)->handle($reservation, $landlord, true);

    expect(fn () => app(ApproveReservation::class)->handle($reservation->fresh(), $landlord, true))
        ->toThrow(ValidationException::class);

    expect(User::where('email', $reservation->email)->count())->toBe(1);
    Notification::assertSentTimes(TenantAccountCreated::class, 1);
});

test('approval fails cleanly when the username was taken meanwhile', function () {
    Notification::fake();
    [$landlord, $reservation] = pendingReservation(['desired_username' => 'taken']);
    User::factory()->create()->forceFill(['username' => 'taken'])->save();

    expect(fn () => app(ApproveReservation::class)->handle($reservation, $landlord, true))
        ->toThrow(ValidationException::class);

    expect($reservation->fresh()->status)->toBe(ReservationStatus::Pending);
});

test('approval fails cleanly when the email got an account meanwhile', function () {
    Notification::fake();
    [$landlord, $reservation] = pendingReservation(['email' => 'juana@example.test']);
    User::factory()->create(['email' => 'Juana@Example.test']);

    expect(fn () => app(ApproveReservation::class)->handle($reservation, $landlord, true))
        ->toThrow(ValidationException::class);

    expect($reservation->fresh()->status)->toBe(ReservationStatus::Pending);
});

test('approval fails cleanly when the unit filled up meanwhile', function () {
    Notification::fake();
    [$landlord, $reservation] = pendingReservation();
    Reservation::factory()->for($reservation->unit)->status(ReservationStatus::Approved)->create();

    expect(fn () => app(ApproveReservation::class)->handle($reservation, $landlord, true))
        ->toThrow(ValidationException::class);

    expect($reservation->fresh()->status)->toBe(ReservationStatus::Pending)
        ->and(User::where('email', $reservation->email)->exists())->toBeFalse();
});

test('rejecting stores the reason, emails the applicant and frees nothing extra', function () {
    Notification::fake();
    [$landlord, $reservation] = pendingReservation();

    app(RejectReservation::class)->handle($reservation, $landlord, 'Unit already promised.');

    $fresh = $reservation->fresh();
    expect($fresh->status)->toBe(ReservationStatus::Rejected)
        ->and($fresh->rejection_reason)->toBe('Unit already promised.')
        ->and($fresh->reviewed_by)->toBe($landlord->id);

    Notification::assertSentOnDemand(ReservationRejected::class, fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === $reservation->email);
});

test('rejecting requires a reason and a pending reservation', function () {
    Notification::fake();
    [$landlord, $reservation] = pendingReservation();

    expect(fn () => app(RejectReservation::class)->handle($reservation, $landlord, '  '))
        ->toThrow(ValidationException::class);

    app(RejectReservation::class)->handle($reservation, $landlord, 'No.');

    expect(fn () => app(RejectReservation::class)->handle($reservation->fresh(), $landlord, 'Again'))
        ->toThrow(ValidationException::class);
});

test('resending login details rotates the temporary password', function () {
    Notification::fake();
    [$landlord, $reservation] = pendingReservation();
    $tenant = app(ApproveReservation::class)->handle($reservation, $landlord, true);
    $oldHash = $tenant->fresh()->password;

    app(ResendLoginDetails::class)->handle($reservation->fresh());

    expect($tenant->fresh()->password)->not->toBe($oldHash);
    Notification::assertSentToTimes($tenant, TenantAccountCreated::class, 2);
});

test('resending is refused once the tenant has set their own password', function () {
    Notification::fake();
    [$landlord, $reservation] = pendingReservation();
    $tenant = app(ApproveReservation::class)->handle($reservation, $landlord, true);
    $tenant->forceFill(['must_change_password' => false])->save();

    expect(fn () => app(ResendLoginDetails::class)->handle($reservation->fresh()))
        ->toThrow(ValidationException::class);
});

test('pruning deletes files of old rejected reservations only', function () {
    Storage::fake(config('filesystems.sensitive_disk'));
    $disk = Storage::disk(config('filesystems.sensitive_disk'));

    [, $old] = pendingReservation();
    [, $recent] = pendingReservation();
    [, $pending] = pendingReservation();

    foreach ([$old, $recent, $pending] as $reservation) {
        $disk->put($reservation->valid_id_path, 'id');
        $disk->put($reservation->downpayment_proof_path, 'proof');
    }

    $old->forceFill(['status' => ReservationStatus::Rejected, 'reviewed_at' => now()->subDays(31)])->save();
    $recent->forceFill(['status' => ReservationStatus::Rejected, 'reviewed_at' => now()->subDays(5)])->save();

    $this->artisan('reservations:prune-files')->assertSuccessful();

    $disk->assertMissing($old->valid_id_path);
    $disk->assertMissing($old->downpayment_proof_path);
    $disk->assertExists($recent->valid_id_path);
    $disk->assertExists($pending->valid_id_path);
    expect($old->fresh()->hasFiles())->toBeFalse()
        ->and($recent->fresh()->hasFiles())->toBeTrue();
});

test('cancelling releases the slot, disables the account and removes the membership', function () {
    Notification::fake();
    [$landlord, $reservation] = pendingReservation();
    $tenant = app(ApproveReservation::class)->handle($reservation, $landlord, true);

    app(CancelReservation::class)->handle($reservation->fresh(), 'Applicant never showed up.');

    $fresh = $reservation->fresh();
    expect($fresh->status)->toBe(ReservationStatus::Cancelled)
        ->and($fresh->cancelled_at)->not->toBeNull()
        ->and($fresh->cancellation_reason)->toBe('Applicant never showed up.')
        ->and($tenant->fresh()->isDisabled())->toBeTrue()
        ->and($tenant->fresh()->belongsToTeam($landlord->currentTeam))->toBeFalse()
        ->and($reservation->unit->fresh()->hasRoomForAnotherTenant())->toBeTrue();
});

test('only an approved reservation can be cancelled, and a reason is required', function () {
    Notification::fake();
    [$landlord, $reservation] = pendingReservation();

    expect(fn () => app(CancelReservation::class)->handle($reservation, 'Because'))
        ->toThrow(ValidationException::class);

    app(ApproveReservation::class)->handle($reservation, $landlord, true);

    expect(fn () => app(CancelReservation::class)->handle($reservation->fresh(), ' '))
        ->toThrow(ValidationException::class);
});
