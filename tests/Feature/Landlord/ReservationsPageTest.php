<?php

use App\Actions\Reservations\ApproveReservation;
use App\Enums\ReservationStatus;
use App\Enums\TeamRole;
use App\Enums\UnitStatus;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\TenantAccountCreated;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * @return array{0: User, 1: Reservation}
 */
function landlordWithReservation(): array
{
    $landlord = User::factory()->create();
    $unit = Unit::factory()->for(Property::factory()->for($landlord->currentTeam))->create(['status' => UnitStatus::Vacant]);

    return [$landlord, Reservation::factory()->for($unit)->create()];
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

test('a landlord can approve a reservation with the downpayment confirmed', function () {
    Notification::fake();
    [$landlord, $reservation] = landlordWithReservation();
    $this->actingAs($landlord);

    Livewire::test('pages::landlord.reservations')
        ->call('open', $reservation->id)
        ->set('downpaymentConfirmed', true)
        ->call('approve')
        ->assertHasNoErrors();

    expect($reservation->fresh()->status)->toBe(ReservationStatus::Approved);
    Notification::assertSentTimes(TenantAccountCreated::class, 1);
});

test('approving without the confirmation shows an error and changes nothing', function () {
    Notification::fake();
    [$landlord, $reservation] = landlordWithReservation();
    $this->actingAs($landlord);

    Livewire::test('pages::landlord.reservations')
        ->call('open', $reservation->id)
        ->call('approve')
        ->assertHasErrors('reservation');

    expect($reservation->fresh()->status)->toBe(ReservationStatus::Pending);
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

test('staff can view reservations but cannot approve or reject', function () {
    Notification::fake();
    [$landlord, $reservation] = landlordWithReservation();
    $staff = reservationTeamMember($landlord, TeamRole::Member);
    $this->actingAs($staff);

    Livewire::test('pages::landlord.reservations')
        ->assertSee($reservation->fullName())
        ->call('open', $reservation->id)
        ->assertDontSee('Approve')
        ->set('downpaymentConfirmed', true)
        ->call('approve')
        ->assertForbidden();

    expect($reservation->fresh()->status)->toBe(ReservationStatus::Pending);
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
    [$landlord, $reservation] = landlordWithReservation();
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

test('a landlord can cancel an approved reservation from the page but staff cannot', function () {
    Notification::fake();
    [$landlord, $reservation] = landlordWithReservation();
    app(ApproveReservation::class)->handle($reservation, $landlord, true);
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
