<?php

use App\Enums\ListingStatus;
use App\Enums\PaymentMethod;
use App\Enums\ReservationStatus;
use App\Enums\TeamRole;
use App\Enums\UnitStatus;
use App\Models\ListingPhoto;
use App\Models\PaymentChannel;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Team;
use App\Models\Unit;
use App\Models\UnitListing;
use App\Models\User;
use App\Notifications\ReservationSubmitted;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Exceptions\PublicPropertyNotFoundException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('media');
    Storage::fake('sensitive');
    RateLimiter::clear('reservation-attempts:127.0.0.1');
    RateLimiter::clear('reservation-submissions:127.0.0.1');
});

/**
 * @return array{listing: UnitListing, team: Team, channel: PaymentChannel}
 */
function reservableListing(?int $downpayment = 2000): array
{
    $team = Team::factory()->create();
    $unit = Unit::factory()
        ->for(Property::factory()->for($team)->create())
        ->create(['status' => UnitStatus::Vacant, 'price' => 5000]);

    $listing = UnitListing::factory()->for($unit)->approved()->create(['downpayment_amount' => $downpayment]);
    ListingPhoto::factory()->for($listing, 'listing')->create();
    $channel = PaymentChannel::factory()->for($team)->create(['account_name' => 'Maria Landlord']);

    return compact('listing', 'team', 'channel');
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function reservationInput(PaymentChannel $channel, array $overrides = []): array
{
    return array_merge([
        'desired_username' => 'juan.tenant',
        'email' => 'juan@example.com',
        'first_name' => 'Juan',
        'last_name' => 'Dela Cruz',
        'age' => '21',
        'address' => '12 Rizal St, Lipa',
        'downpayment_amount' => '2000',
        'payment_channel_id' => $channel->id,
        'downpayment_reference' => '1234567890123',
        'consent' => true,
        'valid_id' => UploadedFile::fake()->image('id.jpg'),
        'proof' => UploadedFile::fake()->image('receipt.png'),
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $input
 */
function submitReservation(UnitListing $listing, array $input): Testable
{
    $component = Livewire::test('pages::reserve', ['listing' => $listing->id]);

    foreach ($input as $key => $value) {
        $component->set($key, $value);
    }

    return $component->call('submit');
}

test('a guest can submit a reservation and gets a reference code', function () {
    ['listing' => $listing, 'team' => $team, 'channel' => $channel] = reservableListing();

    $component = submitReservation($listing, reservationInput($channel))->assertHasNoErrors();

    $reservation = Reservation::firstOrFail();

    expect($reservation->status)->toBe(ReservationStatus::Pending)
        ->and($reservation->team_id)->toBe($team->id)
        ->and($reservation->unit_id)->toBe($listing->unit_id)
        ->and($reservation->unit_listing_id)->toBe($listing->id)
        ->and($reservation->downpayment_method)->toBe(PaymentMethod::Gcash)
        ->and($reservation->desired_username)->toBe('juan.tenant')
        ->and($reservation->age)->toBe(21)
        ->and($reservation->consented_at)->not->toBeNull()
        ->and(User::where('email', 'juan@example.com')->exists())->toBeFalse();

    $component->assertSee($reservation->code);
});

test('the id and payment proof are stored on the private sensitive disk only', function () {
    ['listing' => $listing, 'channel' => $channel] = reservableListing();

    submitReservation($listing, reservationInput($channel))->assertHasNoErrors();

    $reservation = Reservation::firstOrFail();

    Storage::disk('sensitive')->assertExists($reservation->valid_id_path);
    Storage::disk('sensitive')->assertExists($reservation->downpayment_proof_path);
    Storage::disk('media')->assertMissing($reservation->valid_id_path);
    Storage::disk('media')->assertMissing($reservation->downpayment_proof_path);
});

test('the team owner and managers are notified but staff are not', function () {
    ['listing' => $listing, 'team' => $team, 'channel' => $channel] = reservableListing();

    $owner = User::factory()->create();
    $manager = User::factory()->create();
    $staff = User::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner]);
    $team->members()->attach($manager, ['role' => TeamRole::Admin]);
    $team->members()->attach($staff, ['role' => TeamRole::Member]);

    submitReservation($listing, reservationInput($channel))->assertHasNoErrors();

    expect($owner->notifications()->count())->toBe(1)
        ->and($owner->notifications()->first()->type)->toBe(ReservationSubmitted::class)
        ->and($manager->notifications()->count())->toBe(1)
        ->and($staff->notifications()->count())->toBe(0);
});

test('the form shows each payment channel with its QR and a reminder to check the account name', function () {
    ['listing' => $listing, 'channel' => $channel] = reservableListing();

    Livewire::test('pages::reserve', ['listing' => $listing->id])
        ->assertSee('Maria Landlord')
        ->assertSee($channel->qrUrl(), false)
        ->assertSee('Check that the account name matches the landlord before paying.')
        ->assertSee('2,000.00');
});

test('the form is replaced by a closed message when the team has no active payment channel', function () {
    ['listing' => $listing, 'channel' => $channel] = reservableListing();
    $channel->forceFill(['is_active' => false])->save();

    Livewire::test('pages::reserve', ['listing' => $listing->id])
        ->assertSee('This landlord is not accepting online reservations right now.')
        ->assertDontSee('Send reservation');

    submitReservation($listing, reservationInput($channel))->assertNotFound();
    expect(Reservation::count())->toBe(0);
});

test('a listing that is not publicly visible cannot be opened for reservation', function (string $state) {
    $team = Team::factory()->create();
    $unit = Unit::factory()->for(Property::factory()->for($team)->create())->create(['status' => UnitStatus::Vacant]);
    $listing = UnitListing::factory()->for($unit)->{$state}()->create();
    PaymentChannel::factory()->for($team)->create();

    $this->get(route('listings.reserve', $listing))->assertNotFound();
})->with(['pendingReview', 'rejected']);

test('the reserve page renders for a guest on a visible listing', function () {
    ['listing' => $listing] = reservableListing();

    $this->get(route('listings.reserve', $listing))->assertOk()->assertSee('Reserve this unit');
});

test('a listing taken down mid-session can no longer be reserved', function () {
    ['listing' => $listing, 'channel' => $channel] = reservableListing();

    $component = Livewire::test('pages::reserve', ['listing' => $listing->id]);

    foreach (reservationInput($channel) as $key => $value) {
        $component->set($key, $value);
    }

    $listing->forceFill(['status' => ListingStatus::Unlisted])->save();

    expect(fn () => $component->call('submit'))->toThrow(ModelNotFoundException::class);
    expect(Reservation::count())->toBe(0);
});

test('a full unit cannot be reserved', function () {
    ['listing' => $listing] = reservableListing();
    $listing->unit->forceFill(['status' => UnitStatus::Occupied])->save();

    $this->get(route('listings.reserve', $listing))->assertNotFound();
});

test('the listing id is locked so it cannot be swapped to another listing', function () {
    ['listing' => $listing] = reservableListing();
    ['listing' => $other] = reservableListing();

    Livewire::test('pages::reserve', ['listing' => $listing->id])->set('listingId', $other->id);
})->throws(CannotUpdateLockedPropertyException::class);

test('team id cannot be spoofed and always comes from the unit', function () {
    ['listing' => $listing] = reservableListing();
    $otherTeam = Team::factory()->create();

    Livewire::test('pages::reserve', ['listing' => $listing->id])
        ->set('team_id', $otherTeam->id);
})->throws(PublicPropertyNotFoundException::class);

test('a payment channel from another team is rejected', function () {
    ['listing' => $listing, 'channel' => $channel] = reservableListing();
    $foreign = PaymentChannel::factory()->for(Team::factory()->create())->create();

    submitReservation($listing, reservationInput($channel, ['payment_channel_id' => $foreign->id]))
        ->assertHasErrors(['payment_channel_id']);

    expect(Reservation::count())->toBe(0);
});

test('an inactive payment channel is rejected', function () {
    ['listing' => $listing, 'team' => $team, 'channel' => $channel] = reservableListing();
    $inactive = PaymentChannel::factory()->for($team)->inactive()->create();

    submitReservation($listing, reservationInput($channel, ['payment_channel_id' => $inactive->id]))
        ->assertHasErrors(['payment_channel_id']);
});

test('submitting without proof of payment fails', function () {
    ['listing' => $listing, 'channel' => $channel] = reservableListing();

    submitReservation($listing, reservationInput($channel, ['proof' => null]))->assertHasErrors(['proof']);

    expect(Reservation::count())->toBe(0);
});

test('reservation input is validated', function (array $overrides, string $field) {
    ['listing' => $listing, 'channel' => $channel] = reservableListing();

    submitReservation($listing, reservationInput($channel, $overrides))->assertHasErrors([$field]);

    expect(Reservation::count())->toBe(0);
})->with([
    'username too short' => [['desired_username' => 'abc'], 'desired_username'],
    'username with a space' => [['desired_username' => 'juan tenant'], 'desired_username'],
    'username with a symbol' => [['desired_username' => 'juan@tenant'], 'desired_username'],
    'no username' => [['desired_username' => ''], 'desired_username'],
    'invalid email' => [['email' => 'not-an-email'], 'email'],
    'under 18' => [['age' => '17'], 'age'],
    'not a number age' => [['age' => 'twenty'], 'age'],
    'no address' => [['address' => ''], 'address'],
    'zero downpayment' => [['downpayment_amount' => '0'], 'downpayment_amount'],
    'below the requested downpayment' => [['downpayment_amount' => '1999.99'], 'downpayment_amount'],
    'reference too short' => [['downpayment_reference' => 'abc'], 'downpayment_reference'],
    'no consent' => [['consent' => false], 'consent'],
    'no channel chosen' => [['payment_channel_id' => null], 'payment_channel_id'],
]);

test('age 18 is accepted', function () {
    ['listing' => $listing, 'channel' => $channel] = reservableListing();

    submitReservation($listing, reservationInput($channel, ['age' => '18']))->assertHasNoErrors();
});

test('the valid id must be a jpg, png or pdf under 5 MB', function () {
    ['listing' => $listing, 'channel' => $channel] = reservableListing();

    submitReservation($listing, reservationInput($channel, ['valid_id' => UploadedFile::fake()->create('id.gif', 100, 'image/gif')]))
        ->assertHasErrors(['valid_id']);
    submitReservation($listing, reservationInput($channel, ['valid_id' => UploadedFile::fake()->create('id.pdf', 6000, 'application/pdf')]))
        ->assertHasErrors(['valid_id']);
    submitReservation($listing, reservationInput($channel, ['valid_id' => UploadedFile::fake()->create('id.pdf', 200, 'application/pdf')]))
        ->assertHasNoErrors();
});

test('the payment proof must be an image', function () {
    ['listing' => $listing, 'channel' => $channel] = reservableListing();

    submitReservation($listing, reservationInput($channel, ['proof' => UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf')]))
        ->assertHasErrors(['proof']);
});

test('a downpayment is not enforced against a minimum when the listing sets none', function () {
    ['listing' => $listing, 'channel' => $channel] = reservableListing(downpayment: null);

    submitReservation($listing, reservationInput($channel, ['downpayment_amount' => '500']))->assertHasNoErrors();
});

test('usernames and emails already in use are rejected', function () {
    ['listing' => $listing, 'channel' => $channel] = reservableListing();
    User::factory()->create(['email' => 'juan@example.com']);
    $taken = User::factory()->create();
    $taken->forceFill(['username' => 'juan.tenant'])->save();

    submitReservation($listing, reservationInput($channel))->assertHasErrors(['email', 'desired_username']);
});

test('an email match with an existing account is case insensitive', function () {
    ['listing' => $listing, 'channel' => $channel] = reservableListing();
    User::factory()->create(['email' => 'juan@example.com']);

    submitReservation($listing, reservationInput($channel, ['email' => 'JUAN@Example.com']))->assertHasErrors(['email']);
});

test('a username held by another pending reservation is rejected but frees up once it is rejected', function () {
    ['listing' => $listing, 'channel' => $channel] = reservableListing();
    $existing = Reservation::factory()->create(['unit_id' => $listing->unit_id, 'desired_username' => 'juan.tenant']);

    submitReservation($listing, reservationInput($channel))->assertHasErrors(['desired_username']);

    $existing->forceFill(['status' => ReservationStatus::Rejected])->save();

    submitReservation($listing, reservationInput($channel))->assertHasNoErrors();
});

test('a second pending reservation from the same email for the same unit is rejected', function () {
    ['listing' => $listing, 'channel' => $channel] = reservableListing();
    Reservation::factory()->create(['unit_id' => $listing->unit_id, 'email' => 'juan@example.com']);

    submitReservation($listing, reservationInput($channel, ['desired_username' => 'another.name']))
        ->assertHasErrors(['email']);
});

test('the same email may apply for a different unit', function () {
    ['listing' => $listing, 'channel' => $channel] = reservableListing();
    Reservation::factory()->create(['email' => 'juan@example.com']);

    submitReservation($listing, reservationInput($channel))->assertHasNoErrors(['email']);
});

test('submissions are rate limited per connection', function () {
    ['listing' => $listing, 'channel' => $channel] = reservableListing();

    foreach (range(1, 5) as $i) {
        submitReservation($listing, reservationInput($channel, [
            'desired_username' => "tenant.number{$i}",
            'email' => "tenant{$i}@example.com",
        ]))->assertHasNoErrors();
    }

    submitReservation($listing, reservationInput($channel, [
        'desired_username' => 'tenant.number6',
        'email' => 'tenant6@example.com',
    ]))->assertHasErrors(['form']);

    expect(Reservation::count())->toBe(5);
});

test('a filled honeypot looks successful but stores nothing', function () {
    ['listing' => $listing, 'channel' => $channel] = reservableListing();

    submitReservation($listing, reservationInput($channel, ['website' => 'https://spam.example']))
        ->assertHasNoErrors();

    expect(Reservation::count())->toBe(0);
    Storage::disk('sensitive')->assertDirectoryEmpty('/');
});
