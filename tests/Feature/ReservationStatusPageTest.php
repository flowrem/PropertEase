<?php

use App\Enums\ListingStatus;
use App\Enums\ReservationStatus;
use App\Enums\TeamRole;
use App\Models\PaymentChannel;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Unit;
use App\Models\UnitListing;
use App\Models\User;
use App\Notifications\DownpaymentSubmitted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('sensitive');
    RateLimiter::clear('reservation-lookup:127.0.0.1');
});

/**
 * @return array{0: Reservation, 1: PaymentChannel, 2: User}
 */
function reservedForStatusPage(array $overrides = []): array
{
    $landlord = User::factory()->create();
    $unit = Unit::factory()->for(Property::factory()->for($landlord->currentTeam))->create();
    $listing = UnitListing::factory()->for($unit)->create(['status' => ListingStatus::Approved, 'downpayment_amount' => 2000]);
    $channel = PaymentChannel::factory()->for($landlord->currentTeam)->create();
    $reservation = Reservation::factory()->for($unit)->reserved()->create(['unit_listing_id' => $listing->id, 'email' => 'juan@example.com', ...$overrides]);

    return [$reservation, $channel, $landlord];
}

function grantStatusAccess(Reservation $reservation): void
{
    session()->put(Reservation::STATUS_ACCESS_SESSION_KEY, [$reservation->code]);
}

test('the emailed signed link opens the status page and remembers the visitor', function () {
    [$reservation] = reservedForStatusPage();

    $this->get($reservation->statusUrl())
        ->assertOk()
        ->assertSee($reservation->code)
        ->assertSee('The unit is held for you');

    expect(session(Reservation::STATUS_ACCESS_SESSION_KEY))->toContain($reservation->code);

    $this->get(route('reservations.status', ['code' => $reservation->code]))->assertOk();
});

test('without the signed link or the code and email, the page sends visitors to the lookup form', function () {
    [$reservation] = reservedForStatusPage();

    $this->get(route('reservations.status', ['code' => $reservation->code]))
        ->assertRedirect(route('reservations.lookup', ['code' => $reservation->code]));

    $this->get(route('reservations.status', ['code' => $reservation->code, 'signature' => 'forged']))
        ->assertRedirect(route('reservations.lookup', ['code' => $reservation->code]));
});

test('the lookup opens the reservation only when the code and email both match', function () {
    [$reservation] = reservedForStatusPage();

    Livewire::test('pages::reservation-lookup')
        ->set('code', $reservation->code)
        ->set('email', 'someone.else@example.com')
        ->call('open')
        ->assertHasErrors(['code']);

    expect(session(Reservation::STATUS_ACCESS_SESSION_KEY))->toBeNull();

    Livewire::test('pages::reservation-lookup')
        ->set('code', strtolower($reservation->code))
        ->set('email', 'JUAN@example.com')
        ->call('open')
        ->assertHasNoErrors()
        ->assertRedirect(route('reservations.status', ['code' => $reservation->code]));

    expect(session(Reservation::STATUS_ACCESS_SESSION_KEY))->toContain($reservation->code);
});

test('the lookup is rate limited', function () {
    [$reservation] = reservedForStatusPage();

    foreach (range(1, 10) as $attempt) {
        Livewire::test('pages::reservation-lookup')->set('code', 'WRONG123')->set('email', 'juan@example.com')->call('open');
    }

    Livewire::test('pages::reservation-lookup')
        ->set('code', $reservation->code)
        ->set('email', 'juan@example.com')
        ->call('open')
        ->assertHasErrors(['code'])
        ->assertSee('Too many tries');
});

test('the deadline is shown in Philippine time', function () {
    [$reservation] = reservedForStatusPage(['expires_at' => '2026-09-30 09:00:00']);
    grantStatusAccess($reservation);

    Livewire::test('pages::reservation-status', ['code' => $reservation->code])
        ->assertSee('Pay the downpayment by Sep 30, 2026, 5:00 PM or this reservation ends.');
});

test('an applicant sends the downpayment, which keeps the unit held past the deadline and tells the landlord and managers', function () {
    [$reservation, $channel, $landlord] = reservedForStatusPage();
    $manager = User::factory()->create();
    $staff = User::factory()->create();
    $landlord->currentTeam->members()->attach($manager, ['role' => TeamRole::Admin]);
    $landlord->currentTeam->members()->attach($staff, ['role' => TeamRole::Member]);
    grantStatusAccess($reservation);

    Livewire::test('pages::reservation-status', ['code' => $reservation->code])
        ->set('payment_channel_id', $channel->id)
        ->set('downpayment_amount', '2000')
        ->set('downpayment_reference', '1234567890')
        ->set('proof', UploadedFile::fake()->image('receipt.png'))
        ->call('submitDownpayment')
        ->assertHasNoErrors()
        ->assertSee('Downpayment sent');

    $reservation->refresh();

    expect($reservation->status)->toBe(ReservationStatus::Reserved)
        ->and($reservation->downpayment_amount)->toEqual('2000.00')
        ->and($reservation->payment_channel_id)->toBe($channel->id)
        ->and($reservation->downpayment_method)->toBe($channel->method)
        ->and($reservation->downpayment_submitted_at)->not->toBeNull();
    Storage::disk('sensitive')->assertExists($reservation->downpayment_proof_path);

    expect($landlord->notifications()->where('type', DownpaymentSubmitted::class)->count())->toBe(1)
        ->and($manager->notifications()->count())->toBe(1)
        ->and($staff->notifications()->count())->toBe(0);

    $this->travel(4)->days();

    expect($reservation->fresh()->holdsSlot())->toBeTrue()
        ->and($reservation->unit->fresh()->hasRoomForAnotherTenant())->toBeFalse()
        ->and(Reservation::overdue()->whereKey($reservation->id)->exists())->toBeFalse();
});

test('a downpayment sent after the deadline is refused', function () {
    [$reservation, $channel] = reservedForStatusPage();
    grantStatusAccess($reservation);

    $component = Livewire::test('pages::reservation-status', ['code' => $reservation->code]);

    $this->travel(3)->days();
    $this->travel(1)->minutes();

    $component
        ->set('payment_channel_id', $channel->id)
        ->set('downpayment_amount', '2000')
        ->set('downpayment_reference', '1234567890')
        ->set('proof', UploadedFile::fake()->image('receipt.png'))
        ->call('submitDownpayment')
        ->assertHasErrors(['proof']);

    expect($reservation->fresh()->downpayment_submitted_at)->toBeNull();
    Storage::disk('sensitive')->assertDirectoryEmpty("reservations/{$reservation->code}");

    Livewire::test('pages::reservation-status', ['code' => $reservation->code])
        ->assertSee('This reservation has ended')
        ->assertDontSee('Send downpayment');
});

test('the downpayment must go to one of this landlord\'s active accounts and cover the requested amount', function (Closure $input, string $field) {
    [$reservation, $channel] = reservedForStatusPage();
    grantStatusAccess($reservation);

    $values = [
        'payment_channel_id' => $channel->id,
        'downpayment_amount' => '2000',
        'downpayment_reference' => '1234567890',
        ...$input($channel),
    ];

    $component = Livewire::test('pages::reservation-status', ['code' => $reservation->code]);

    foreach ($values as $key => $value) {
        $component->set($key, $value);
    }

    $component->set('proof', UploadedFile::fake()->image('receipt.png'))
        ->call('submitDownpayment')
        ->assertHasErrors([$field]);

    expect($reservation->fresh()->downpayment_submitted_at)->toBeNull();
})->with([
    'another landlord\'s account' => [fn () => ['payment_channel_id' => PaymentChannel::factory()->create()->id], 'payment_channel_id'],
    'an inactive account' => [function (PaymentChannel $channel) {
        $channel->forceFill(['is_active' => false])->save();

        return [];
    }, 'payment_channel_id'],
    'less than requested' => [fn () => ['downpayment_amount' => '1999.99'], 'downpayment_amount'],
    'a short reference' => [fn () => ['downpayment_reference' => 'abc'], 'downpayment_reference'],
]);

test('the downpayment cannot be sent twice or before the landlord accepts', function () {
    [$sent] = reservedForStatusPage();
    $sent->forceFill(['downpayment_submitted_at' => now()])->save();
    [$pending, $channel] = reservedForStatusPage(['status' => ReservationStatus::Pending, 'expires_at' => null]);

    foreach ([$sent, $pending] as $reservation) {
        session()->put(Reservation::STATUS_ACCESS_SESSION_KEY, [$reservation->code]);

        Livewire::test('pages::reservation-status', ['code' => $reservation->code])
            ->set('payment_channel_id', PaymentChannel::query()->where('team_id', $reservation->team_id)->value('id'))
            ->set('downpayment_amount', '2000')
            ->set('downpayment_reference', '1234567890')
            ->set('proof', UploadedFile::fake()->image('receipt.png'))
            ->call('submitDownpayment')
            ->assertHasErrors(['proof']);
    }

    expect($pending->fresh()->downpayment_submitted_at)->toBeNull();
});

test('each state explains what happens next', function (array $state, string $message) {
    [$reservation] = reservedForStatusPage($state);
    grantStatusAccess($reservation);

    Livewire::test('pages::reservation-status', ['code' => $reservation->code])->assertSee($message);
})->with([
    'pending' => [['status' => ReservationStatus::Pending, 'expires_at' => null], 'Don\'t pay anything yet.'],
    'confirmed' => [['status' => ReservationStatus::Confirmed], 'The landlord confirmed your downpayment'],
    'expired' => [['status' => ReservationStatus::Expired], 'No downpayment arrived by the deadline'],
    'rejected' => [['status' => ReservationStatus::Rejected, 'rejection_reason' => 'Unit already promised.'], 'Unit already promised.'],
]);
