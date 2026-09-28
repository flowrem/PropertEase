<?php

use App\Actions\Reservations\AcceptReservation;
use App\Actions\Reservations\CancelReservation;
use App\Actions\Reservations\ConfirmReservation;
use App\Actions\Reservations\ExtendReservation;
use App\Actions\Reservations\RejectReservation;
use App\Actions\Reservations\ResendLoginDetails;
use App\Enums\ReservationStatus;
use App\Enums\TeamRole;
use App\Enums\UnitStatus;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\ReservationAccepted;
use App\Notifications\ReservationCancelled;
use App\Notifications\ReservationExtended;
use App\Notifications\ReservationRejected;
use App\Notifications\TenantAccountCreated;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
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

/**
 * A reserved reservation whose applicant sent the downpayment, ready to confirm.
 *
 * @return array{0: User, 1: Reservation}
 */
function reservationToConfirm(array $overrides = []): array
{
    $landlord = User::factory()->create();
    $unit = Unit::factory()->for(Property::factory()->for($landlord->currentTeam))->create(['status' => UnitStatus::Vacant]);
    $reservation = Reservation::factory()->for($unit)->reserved()->downpaymentSent()->create($overrides);

    return [$landlord, $reservation];
}

test('accepting holds the unit for the team\'s days and emails the applicant the deadline and link', function () {
    Notification::fake();
    [$landlord, $reservation] = pendingReservation();
    $landlord->currentTeam->forceFill(['reservation_hold_days' => 5])->save();

    app(AcceptReservation::class)->handle($reservation, $landlord);

    $fresh = $reservation->fresh();
    expect($fresh->status)->toBe(ReservationStatus::Reserved)
        ->and($fresh->expires_at->toDateTimeString())->toBe(now()->addDays(5)->toDateTimeString())
        ->and($fresh->reviewed_by)->toBe($landlord->id)
        ->and(User::where('email', $reservation->email)->exists())->toBeFalse()
        ->and($reservation->unit->fresh()->hasRoomForAnotherTenant())->toBeFalse();

    Notification::assertSentOnDemand(ReservationAccepted::class, function (ReservationAccepted $notification, array $channels, object $notifiable) use ($reservation) {
        $mail = $notification->toMail($notifiable);

        return $notifiable->routes['mail'] === $reservation->email
            && str_contains(implode(' ', $mail->introLines), $reservation->fresh()->deadlineForDisplay())
            && $mail->actionUrl === $reservation->fresh()->statusUrl();
    });
});

test('only a pending reservation for a unit with room can be accepted', function () {
    Notification::fake();
    [$landlord, $reservation] = pendingReservation();
    Reservation::factory()->for($reservation->unit)->confirmed()->create();

    expect(fn () => app(AcceptReservation::class)->handle($reservation, $landlord))
        ->toThrow(ValidationException::class);

    expect($reservation->fresh()->status)->toBe(ReservationStatus::Pending);

    [$landlord, $reserved] = reservationToConfirm();

    expect(fn () => app(AcceptReservation::class)->handle($reserved, $landlord))
        ->toThrow(ValidationException::class);

    Notification::assertNothingSent();
});

test('accepting fails cleanly when the username or email was taken meanwhile', function (array $reservationValues, Closure $takeIt) {
    Notification::fake();
    [$landlord, $reservation] = pendingReservation($reservationValues);
    $takeIt();

    expect(fn () => app(AcceptReservation::class)->handle($reservation, $landlord))
        ->toThrow(ValidationException::class);

    expect($reservation->fresh()->status)->toBe(ReservationStatus::Pending);
})->with([
    'username' => [['desired_username' => 'taken'], fn () => User::factory()->create()->forceFill(['username' => 'taken'])->save()],
    'email' => [['email' => 'juana@example.test'], fn () => User::factory()->create(['email' => 'Juana@Example.test'])],
]);

test('confirming is blocked without the downpayment confirmation', function () {
    Notification::fake();
    [$landlord, $reservation] = reservationToConfirm();

    expect(fn () => app(ConfirmReservation::class)->handle($reservation, $landlord, false))
        ->toThrow(ValidationException::class);

    expect($reservation->fresh()->status)->toBe(ReservationStatus::Reserved)
        ->and(User::where('email', $reservation->email)->exists())->toBeFalse();
    Notification::assertNothingSent();
});

test('confirming is refused before the landlord accepts or before the downpayment is sent', function (Closure $makeReservation) {
    Notification::fake();
    [$landlord, $reservation] = $makeReservation();

    expect(fn () => app(ConfirmReservation::class)->handle($reservation, $landlord, true))
        ->toThrow(ValidationException::class);

    expect(User::where('email', $reservation->email)->exists())->toBeFalse();
})->with([
    'still pending' => [fn () => pendingReservation()],
    'no downpayment sent' => [function () {
        $landlord = User::factory()->create();
        $unit = Unit::factory()->for(Property::factory()->for($landlord->currentTeam))->create();

        return [$landlord, Reservation::factory()->for($unit)->reserved()->create()];
    }],
]);

test('confirming creates exactly one tenant account and membership and keeps holding the unit', function () {
    Notification::fake();
    [$landlord, $reservation] = reservationToConfirm(['desired_username' => 'Juana_DC', 'contact_number' => '+639171234567']);

    $tenant = app(ConfirmReservation::class)->handle($reservation, $landlord, true);

    $fresh = $reservation->fresh();
    expect($fresh->status)->toBe(ReservationStatus::Confirmed)
        ->and($fresh->tenant_user_id)->toBe($tenant->id)
        ->and($fresh->downpayment_confirmed_at)->not->toBeNull()
        ->and($fresh->downpayment_confirmed_by)->toBe($landlord->id)
        ->and(User::where('email', $reservation->email)->count())->toBe(1);

    $tenant->refresh();
    expect($tenant->username)->toBe('juana_dc')
        ->and($tenant->contact_number)->toBe('+639171234567')
        ->and($tenant->must_change_password)->toBeTrue()
        ->and($tenant->temporary_password_expires_at->isFuture())->toBeTrue()
        ->and($tenant->current_team_id)->toBe($reservation->team_id)
        ->and($tenant->teamRole($landlord->currentTeam))->toBe(TeamRole::Tenant);

    $this->travel(30)->days();

    expect($reservation->unit->fresh()->hasRoomForAnotherTenant())->toBeFalse();
});

test('the temporary password is only sent by notification and stored hashed', function () {
    Notification::fake();
    [$landlord, $reservation] = reservationToConfirm();

    $tenant = app(ConfirmReservation::class)->handle($reservation, $landlord, true);

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

test('confirming twice is blocked', function () {
    Notification::fake();
    [$landlord, $reservation] = reservationToConfirm();

    app(ConfirmReservation::class)->handle($reservation, $landlord, true);

    expect(fn () => app(ConfirmReservation::class)->handle($reservation->fresh(), $landlord, true))
        ->toThrow(ValidationException::class);

    expect(User::where('email', $reservation->email)->count())->toBe(1);
    Notification::assertSentTimes(TenantAccountCreated::class, 1);
});

test('confirming fails cleanly when the username or email was taken meanwhile', function (array $reservationValues, Closure $takeIt) {
    Notification::fake();
    [$landlord, $reservation] = reservationToConfirm($reservationValues);
    $takeIt();

    expect(fn () => app(ConfirmReservation::class)->handle($reservation, $landlord, true))
        ->toThrow(ValidationException::class);

    expect($reservation->fresh()->status)->toBe(ReservationStatus::Reserved);
})->with([
    'username' => [['desired_username' => 'taken'], fn () => User::factory()->create()->forceFill(['username' => 'taken'])->save()],
    'email' => [['email' => 'juana@example.test'], fn () => User::factory()->create(['email' => 'Juana@Example.test'])],
]);

test('extending moves the deadline on from the current one and emails the applicant', function () {
    Notification::fake();
    [, $reservation] = pendingReservation();
    $reservation->forceFill(['status' => ReservationStatus::Reserved, 'expires_at' => now()->addDay()])->save();

    app(ExtendReservation::class)->handle($reservation, 2);

    expect($reservation->fresh()->expires_at->toDateTimeString())->toBe(now()->addDays(3)->toDateTimeString());
    Notification::assertSentOnDemand(ReservationExtended::class, fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === $reservation->email);
});

test('a reservation whose deadline passed can be extended from now, but only while the unit has room', function () {
    Notification::fake();
    [, $reservation] = pendingReservation();
    $reservation->forceFill(['status' => ReservationStatus::Reserved, 'expires_at' => now()->subHour()])->save();

    app(ExtendReservation::class)->handle($reservation, 2);

    expect($reservation->fresh()->expires_at->toDateTimeString())->toBe(now()->addDays(2)->toDateTimeString());

    $reservation->forceFill(['expires_at' => now()->subHour()])->save();
    Reservation::factory()->for($reservation->unit)->confirmed()->create();

    expect(fn () => app(ExtendReservation::class)->handle($reservation->fresh(), 2))
        ->toThrow(ValidationException::class);
});

test('extending is refused outside 1 to 14 days, once the downpayment is sent, or when not reserved', function (Closure $makeReservation, int $days) {
    Notification::fake();
    $reservation = $makeReservation();
    $deadline = $reservation->expires_at;

    expect(fn () => app(ExtendReservation::class)->handle($reservation, $days))
        ->toThrow(ValidationException::class);

    expect($reservation->fresh()->expires_at?->toDateTimeString())->toBe($deadline?->toDateTimeString());
    Notification::assertNothingSent();
})->with([
    '0 days' => [fn () => Reservation::factory()->reserved()->create(), 0],
    '15 days' => [fn () => Reservation::factory()->reserved()->create(), 15],
    'downpayment sent' => [fn () => Reservation::factory()->reserved()->downpaymentSent()->create(), 2],
    'pending' => [fn () => Reservation::factory()->create(), 2],
    'confirmed' => [fn () => Reservation::factory()->confirmed()->create(), 2],
]);

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
    [$landlord, $reservation] = reservationToConfirm();
    $tenant = app(ConfirmReservation::class)->handle($reservation, $landlord, true);
    $oldHash = $tenant->fresh()->password;

    app(ResendLoginDetails::class)->handle($reservation->fresh());

    expect($tenant->fresh()->password)->not->toBe($oldHash);
    Notification::assertSentToTimes($tenant, TenantAccountCreated::class, 2);
});

test('resending is refused once the tenant has set their own password', function () {
    Notification::fake();
    [$landlord, $reservation] = reservationToConfirm();
    $tenant = app(ConfirmReservation::class)->handle($reservation, $landlord, true);
    $tenant->forceFill(['must_change_password' => false])->save();

    expect(fn () => app(ResendLoginDetails::class)->handle($reservation->fresh()))
        ->toThrow(ValidationException::class);
});

test('pruning deletes files of old rejected reservations only', function () {
    Storage::fake(config('filesystems.sensitive_disk'));
    $disk = Storage::disk(config('filesystems.sensitive_disk'));

    [, $old] = reservationToConfirm();
    [, $recent] = reservationToConfirm();
    [, $pending] = reservationToConfirm();

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

test('cancelling a confirmed reservation releases the slot, disables the account and removes the membership', function () {
    Notification::fake();
    [$landlord, $reservation] = reservationToConfirm();
    $tenant = app(ConfirmReservation::class)->handle($reservation, $landlord, true);

    app(CancelReservation::class)->handle($reservation->fresh(), 'Applicant never showed up.');

    $fresh = $reservation->fresh();
    expect($fresh->status)->toBe(ReservationStatus::Cancelled)
        ->and($fresh->cancelled_at)->not->toBeNull()
        ->and($fresh->cancellation_reason)->toBe('Applicant never showed up.')
        ->and($tenant->fresh()->isDisabled())->toBeTrue()
        ->and($tenant->fresh()->belongsToTeam($landlord->currentTeam))->toBeFalse()
        ->and($reservation->unit->fresh()->hasRoomForAnotherTenant())->toBeTrue();
});

test('cancelling a reserved reservation releases the slot and says the unit is no longer held', function () {
    Notification::fake();
    [, $reservation] = pendingReservation();
    $reservation->forceFill(['status' => ReservationStatus::Reserved, 'expires_at' => now()->addDays(3)])->save();

    app(CancelReservation::class)->handle($reservation->fresh(), 'Unit needs repairs first.');

    expect($reservation->fresh()->status)->toBe(ReservationStatus::Cancelled)
        ->and($reservation->unit->fresh()->hasRoomForAnotherTenant())->toBeTrue();

    Notification::assertSentOnDemand(ReservationCancelled::class, function (ReservationCancelled $notification, array $channels, object $notifiable) {
        return str_contains(implode(' ', $notification->toMail($notifiable)->introLines), 'no longer held for you');
    });
});

test('only a reserved or confirmed reservation can be cancelled, and a reason is required', function () {
    Notification::fake();
    [$landlord, $reservation] = reservationToConfirm();
    [, $pending] = pendingReservation();

    expect(fn () => app(CancelReservation::class)->handle($pending, 'Because'))
        ->toThrow(ValidationException::class);

    expect(fn () => app(CancelReservation::class)->handle($reservation, ' '))
        ->toThrow(ValidationException::class);

    expect($pending->fresh()->status)->toBe(ReservationStatus::Pending)
        ->and($reservation->fresh()->status)->toBe(ReservationStatus::Reserved);
});

test('cancelling emails the applicant and ends their sessions', function () {
    Notification::fake();
    [$landlord, $reservation] = reservationToConfirm();
    $tenant = app(ConfirmReservation::class)->handle($reservation, $landlord, true);
    DB::table('sessions')->insert([
        'id' => 'abc', 'user_id' => $tenant->id, 'payload' => '', 'last_activity' => time(),
    ]);

    app(CancelReservation::class)->handle($reservation->fresh(), 'No show.');

    Notification::assertSentOnDemand(ReservationCancelled::class, fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === $reservation->email);
    expect(DB::table('sessions')->where('user_id', $tenant->id)->exists())->toBeFalse();
});

test('a disabled user is logged out on their next request', function () {
    $user = User::factory()->create();
    $user->forceFill(['disabled_at' => now()])->save();
    $user->switchTeam($user->currentTeam);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});
