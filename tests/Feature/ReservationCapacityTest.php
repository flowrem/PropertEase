<?php

use App\Enums\LeaseStatus;
use App\Enums\ReservationStatus;
use App\Enums\TeamRole;
use App\Enums\UnitStatus;
use App\Models\Lease;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Unit;
use App\Models\User;
use Livewire\Livewire;

test('an approved reservation holds the only slot of a vacant unit', function () {
    $unit = Unit::factory()->create(['status' => UnitStatus::Vacant]);

    expect($unit->hasRoomForAnotherTenant())->toBeTrue();

    Reservation::factory()->for($unit)->status(ReservationStatus::Approved)->create();

    expect($unit->fresh()->hasRoomForAnotherTenant())->toBeFalse()
        ->and($unit->fresh()->slotsAvailable())->toBe(0)
        ->and(Unit::hasRoom()->whereKey($unit->id)->exists())->toBeFalse();
});

test('pending, rejected, cancelled and fulfilled reservations hold nothing', function (ReservationStatus $status) {
    $unit = Unit::factory()->create(['status' => UnitStatus::Vacant]);

    Reservation::factory()->for($unit)->status($status)->create();

    expect($unit->fresh()->hasRoomForAnotherTenant())->toBeTrue()
        ->and(Unit::hasRoom()->whereKey($unit->id)->exists())->toBeTrue();
})->with([
    ReservationStatus::Pending,
    ReservationStatus::Rejected,
    ReservationStatus::Cancelled,
    ReservationStatus::Fulfilled,
]);

test('held reservations and active leases share a multi-tenant unit capacity', function () {
    $unit = Unit::factory()->create([
        'status' => UnitStatus::Occupied,
        'allows_multiple_tenants' => true,
        'tenant_limit' => 3,
    ]);

    Lease::factory()->for($unit)->create(['status' => LeaseStatus::Active]);
    Reservation::factory()->for($unit)->status(ReservationStatus::Approved)->create();

    expect($unit->fresh()->slotsAvailable())->toBe(1)
        ->and($unit->fresh()->hasRoomForAnotherTenant())->toBeTrue();

    Reservation::factory()->for($unit)->status(ReservationStatus::Approved)->create();

    expect($unit->fresh()->slotsAvailable())->toBe(0)
        ->and($unit->fresh()->hasRoomForAnotherTenant())->toBeFalse()
        ->and(Unit::hasRoom()->whereKey($unit->id)->exists())->toBeFalse();
});

test('a holder can be assigned to their held unit and the reservation becomes fulfilled', function () {
    $landlord = User::factory()->create();
    $tenant = User::factory()->create();
    $landlord->currentTeam->members()->attach($tenant, ['role' => TeamRole::Tenant]);

    $unit = Unit::factory()->for(Property::factory()->for($landlord->currentTeam))->create([
        'status' => UnitStatus::Vacant,
        'price' => 5000,
    ]);
    $reservation = Reservation::factory()->for($unit)->status(ReservationStatus::Approved)->create([
        'tenant_user_id' => $tenant->id,
    ]);

    $this->actingAs($landlord);

    Livewire::test('pages::landlord.tenants')
        ->call('manageTenant', $tenant->id)
        ->assertSee($unit->unit_number)
        ->set('unit_id', $unit->id)
        ->call('saveUnitAssignment')
        ->assertHasNoErrors();

    expect($reservation->fresh()->status)->toBe(ReservationStatus::Fulfilled)
        ->and($unit->fresh()->activeLeaseCount())->toBe(1);
});

test('a hold does not let a different tenant take the slot', function () {
    $landlord = User::factory()->create();
    $holder = User::factory()->create();
    $other = User::factory()->create();
    $landlord->currentTeam->members()->attach($holder, ['role' => TeamRole::Tenant]);
    $landlord->currentTeam->members()->attach($other, ['role' => TeamRole::Tenant]);

    $unit = Unit::factory()->for(Property::factory()->for($landlord->currentTeam))->create(['status' => UnitStatus::Vacant]);
    $reservation = Reservation::factory()->for($unit)->status(ReservationStatus::Approved)->create([
        'tenant_user_id' => $holder->id,
    ]);

    $this->actingAs($landlord);

    Livewire::test('pages::landlord.tenants')
        ->call('manageTenant', $other->id)
        ->set('unit_id', $unit->id)
        ->call('saveUnitAssignment')
        ->assertForbidden();

    expect($reservation->fresh()->status)->toBe(ReservationStatus::Approved)
        ->and($unit->fresh()->activeLeaseCount())->toBe(0);
});
