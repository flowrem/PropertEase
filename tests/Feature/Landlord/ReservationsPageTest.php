<?php

use App\Enums\ReservationStatus;
use App\Enums\StayType;
use App\Enums\TeamRole;
use App\Enums\UnitStatus;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\ReservationAccepted;
use App\Notifications\ReservationExtended;
use App\Notifications\TenantAccountCreated;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * @return array{0: User, 1: Reservation}
 */
function landlordWithReservation(?Closure $state = null): array
{
    $landlord = User::factory()->create();
    $unit = Unit::factory()->for(Property::factory()->for($landlord->currentTeam))->create(['status' => UnitStatus::Vacant]);
    $factory = Reservation::factory()->for($unit);

    return [$landlord, ($state ? $state($factory) : $factory)->create()];
}

function reservationTeamMember(User $landlord, TeamRole $role): User
{
    $member = User::factory()->create();
    $landlord->currentTeam->members()->attach($member, ['role' => $role]);
    $member->switchTeam($landlord->currentTeam);

    return $member;
}

test('guests and tenants cannot open the reservations page', function () {
    [$landlord] = landlordWithReservation();
    $tenant = reservationTeamMember($landlord, TeamRole::Tenant);

    $landlord->switchTeam($landlord->currentTeam);
    $this->get(route('reservations'))->assertRedirect(route('login'));
    $this->actingAs($tenant)->get(route('reservations'))->assertForbidden();
});

test('a landlord sees pending reservations and the pending badge', function () {
    [$landlord, $reservation] = landlordWithReservation();
    $landlord->switchTeam($landlord->currentTeam);

    $this->actingAs($landlord)
        ->get(route('reservations'))
        ->assertOk()
        ->assertSee($reservation->fullName())
        ->assertSee($reservation->code);
});

test('reservations are grouped by what the landlord does next, with the deadline for those waiting', function () {
    $landlord = User::factory()->create();
    $unit = fn () => Unit::factory()->for(Property::factory()->for($landlord->currentTeam))->create();
    $pending = Reservation::factory()->for($unit())->create(['first_name' => 'Pia']);
    $waiting = Reservation::factory()->for($unit())->reserved(now()->setDateTime(2026, 9, 30, 9, 0))->create(['first_name' => 'Wes']);
    $overdue = Reservation::factory()->for($unit())->reserved(now()->subHour())->create(['first_name' => 'Ola']);
    $sent = Reservation::factory()->for($unit())->reserved()->downpaymentSent()->create(['first_name' => 'Sam']);
    $confirmed = Reservation::factory()->for($unit())->confirmed()->create(['first_name' => 'Cai']);

    $this->actingAs($landlord);

    $component = Livewire::test('pages::landlord.reservations');

    expect($component->get('active')['review']->pluck('id')->all())->toBe([$pending->id])
        ->and($component->get('active')['waiting']->pluck('id')->all())->toEqualCanonicalizing([$waiting->id, $overdue->id])
        ->and($component->get('active')['check']->pluck('id')->all())->toBe([$sent->id])
        ->and($component->get('active')['moveIn']->pluck('id')->all())->toBe([$confirmed->id]);

    $component->assertSee('Due Sep 30, 2026, 5:00 PM')->assertSee('Deadline passed');

    expect(Reservation::needingLandlord()->pluck('id')->all())->toEqualCanonicalizing([$pending->id, $sent->id]);
});

test('a notification link opens the reservation, but only one of this team\'s', function () {
    [$landlord, $reservation] = landlordWithReservation();
    [, $foreign] = landlordWithReservation();
    $this->actingAs($landlord);

    Livewire::withQueryParams(['reservation' => $reservation->id])
        ->test('pages::landlord.reservations')
        ->assertSet('showDetailModal', true)
        ->assertSee($reservation->email);

    Livewire::withQueryParams(['reservation' => $foreign->id])
        ->test('pages::landlord.reservations')
        ->assertSet('showDetailModal', false)
        ->assertSet('selectedId', null);
});

test('a reservation shows the applicant\'s mobile number as a call link and their stay type', function () {
    [$landlord, $reservation] = landlordWithReservation();
    $reservation->update(['contact_number' => '+639171234567', 'stay_type' => StayType::ShortTerm]);
    $this->actingAs($landlord);

    Livewire::test('pages::landlord.reservations')
        ->call('open', $reservation->id)
        ->assertSeeHtml('href="tel:+639171234567"')
        ->assertSee('0917 123 4567')
        ->assertSee('Short-term');
});

test('a confirmed reservation links to moving the tenant in, a pending one does not', function () {
    [$landlord, $reservation] = landlordWithReservation();
    $this->actingAs($landlord);

    Livewire::test('pages::landlord.reservations')
        ->call('open', $reservation->id)
        ->assertDontSee('Move in');

    $tenant = User::factory()->create();
    $reservation->forceFill(['status' => ReservationStatus::Confirmed, 'tenant_user_id' => $tenant->id])->save();
    $landlord->switchTeam($landlord->currentTeam);

    Livewire::test('pages::landlord.reservations')
        ->call('open', $reservation->id)
        ->assertSee('Move in')
        ->assertSeeHtml('href="'.route('tenants', ['tenant' => $tenant->id]).'"');
});

test('a landlord accepts a pending reservation, which holds the unit and emails the applicant', function () {
    Notification::fake();
    [$landlord, $reservation] = landlordWithReservation();
    $this->actingAs($landlord);

    Livewire::test('pages::landlord.reservations')
        ->call('open', $reservation->id)
        ->assertSee('Accepting holds the unit for 3 days')
        ->call('accept')
        ->assertHasNoErrors();

    expect($reservation->fresh()->status)->toBe(ReservationStatus::Reserved);
    Notification::assertSentOnDemandTimes(ReservationAccepted::class, 1);
    Notification::assertNotSentTo(User::all(), TenantAccountCreated::class);
});

test('a landlord confirms a downpayment they checked, which creates the tenant account', function () {
    Notification::fake();
    [$landlord, $reservation] = landlordWithReservation(fn ($factory) => $factory->reserved()->downpaymentSent());
    $this->actingAs($landlord);

    Livewire::test('pages::landlord.reservations')
        ->call('open', $reservation->id)
        ->call('confirm')
        ->assertHasErrors('reservation')
        ->set('downpaymentConfirmed', true)
        ->call('confirm')
        ->assertHasNoErrors();

    expect($reservation->fresh()->status)->toBe(ReservationStatus::Confirmed);
    Notification::assertSentTimes(TenantAccountCreated::class, 1);
});

test('a landlord extends the deadline of a reservation waiting for its downpayment', function () {
    Notification::fake();
    [$landlord, $reservation] = landlordWithReservation(fn ($factory) => $factory->reserved(now()->addDay()));
    $this->actingAs($landlord);

    Livewire::test('pages::landlord.reservations')
        ->call('open', $reservation->id)
        ->assertSet('extendDays', '3')
        ->set('extendDays', '20')
        ->call('extend')
        ->assertHasErrors('extendDays')
        ->set('extendDays', '2')
        ->call('extend')
        ->assertHasNoErrors();

    expect($reservation->fresh()->expires_at->toDateTimeString())->toBe(now()->addDays(3)->toDateTimeString());
    Notification::assertSentOnDemandTimes(ReservationExtended::class, 1);
});

test('a landlord can reject with a reason', function () {
    Notification::fake();
    [$landlord, $reservation] = landlordWithReservation();
    $this->actingAs($landlord);

    Livewire::test('pages::landlord.reservations')
        ->call('open', $reservation->id)
        ->set('rejectReason', 'Documents are unreadable.')
        ->call('reject')
        ->assertHasNoErrors();

    expect($reservation->fresh()->status)->toBe(ReservationStatus::Rejected);
});

test('staff can view reservations but cannot accept, confirm or extend them', function () {
    Notification::fake();
    [$landlord, $pending] = landlordWithReservation();
    [, $sent] = landlordWithReservation(fn ($factory) => $factory->reserved()->downpaymentSent());
    $sent->forceFill(['team_id' => $landlord->currentTeam->id])->save();
    $staff = reservationTeamMember($landlord, TeamRole::Member);
    $this->actingAs($staff);

    Livewire::test('pages::landlord.reservations')
        ->assertSee($pending->fullName())
        ->call('open', $pending->id)
        ->assertDontSee('Accepting holds the unit')
        ->call('accept')
        ->assertForbidden();

    Livewire::test('pages::landlord.reservations')
        ->call('open', $sent->id)
        ->set('downpaymentConfirmed', true)
        ->call('confirm')
        ->assertForbidden();

    expect($pending->fresh()->status)->toBe(ReservationStatus::Pending)
        ->and($sent->fresh()->status)->toBe(ReservationStatus::Reserved);
});

test('a landlord cannot open another team\'s reservation', function () {
    [, $foreign] = landlordWithReservation();
    $other = User::factory()->create();
    $this->actingAs($other);

    $this->expectException(ModelNotFoundException::class);

    Livewire::test('pages::landlord.reservations')->call('open', $foreign->id);
});

test('reservation files are streamed privately to the owning team only', function () {
    Storage::fake(config('filesystems.sensitive_disk'));
    [$landlord, $reservation] = landlordWithReservation(fn ($factory) => $factory->reserved()->downpaymentSent());
    Storage::disk(config('filesystems.sensitive_disk'))->put($reservation->valid_id_path, 'id-bytes');
    Storage::disk(config('filesystems.sensitive_disk'))->put($reservation->downpayment_proof_path, 'proof-bytes');

    $landlord->switchTeam($landlord->currentTeam);
    $idUrl = route('reservations.files', ['reservation' => $reservation->id, 'kind' => 'id']);
    $proofUrl = route('reservations.files', ['reservation' => $reservation->id, 'kind' => 'proof']);

    $response = $this->actingAs($landlord)->get($idUrl)->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    $this->actingAs($landlord)->get($proofUrl)->assertOk();
    $this->actingAs($landlord)->get(str_replace('/id', '/passport', $idUrl))->assertNotFound();
});

test('a reservation with no downpayment yet has no proof file to show', function () {
    Storage::fake(config('filesystems.sensitive_disk'));
    [$landlord, $reservation] = landlordWithReservation();
    $landlord->switchTeam($landlord->currentTeam);

    $this->actingAs($landlord)
        ->get(route('reservations.files', ['reservation' => $reservation->id, 'kind' => 'proof']))
        ->assertNotFound();
});

test('reservation files are refused for other teams, tenants and guests', function () {
    Storage::fake(config('filesystems.sensitive_disk'));
    [$landlord, $reservation] = landlordWithReservation();
    Storage::disk(config('filesystems.sensitive_disk'))->put($reservation->valid_id_path, 'id-bytes');

    $tenant = reservationTeamMember($landlord, TeamRole::Tenant);
    $landlord->switchTeam($landlord->currentTeam);
    $url = route('reservations.files', ['reservation' => $reservation->id, 'kind' => 'id']);

    $this->actingAs($tenant)->get($url)->assertForbidden();

    $outsider = User::factory()->create();
    $this->actingAs($outsider)->get($url)->assertForbidden();

    $outsider->switchTeam($outsider->currentTeam);
    $this->actingAs($outsider)
        ->get(route('reservations.files', ['reservation' => $reservation->id, 'kind' => 'id', 'current_team' => $outsider->currentTeam->slug]))
        ->assertNotFound();

    auth()->logout();
    $this->get($url)->assertRedirect(route('login'));
});

test('a landlord can cancel a confirmed reservation from the page but staff cannot', function () {
    Notification::fake();
    [$landlord, $reservation] = landlordWithReservation(fn ($factory) => $factory->confirmed());
    $staff = reservationTeamMember($landlord, TeamRole::Member);

    $this->actingAs($staff);
    Livewire::test('pages::landlord.reservations')
        ->call('open', $reservation->id)
        ->set('cancelReason', 'No show.')
        ->call('cancel')
        ->assertForbidden();

    $this->actingAs($landlord);
    Livewire::test('pages::landlord.reservations')
        ->call('open', $reservation->id)
        ->set('cancelReason', 'No show.')
        ->call('cancel')
        ->assertHasNoErrors();

    expect($reservation->fresh()->status)->toBe(ReservationStatus::Cancelled);
});
