<?php

use App\Enums\BillingTiming;
use App\Enums\LeaseStatus;
use App\Enums\TeamRole;
use App\Enums\UnitStatus;
use App\Models\Lease;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $user = User::factory()->create();

    $response = $this->get(route('tenants'));

    $response->assertRedirect(route('login'));
});

test('tenants cannot access the tenants page', function () {
    $landlord = User::factory()->create();
    $tenant = User::factory()->create();

    $landlord->currentTeam->members()->attach($tenant, ['role' => TeamRole::Tenant]);
    $tenant->switchTeam($landlord->currentTeam);

    $response = $this->actingAs($tenant)->get(route('tenants'));

    $response->assertForbidden();
});

test('a landlord sees tenants attached to their team', function () {
    $landlord = User::factory()->create();
    $tenant = User::factory()->create(['name' => 'Juana Dela Cruz']);

    $landlord->currentTeam->members()->attach($tenant, ['role' => TeamRole::Tenant]);
    $landlord->switchTeam($landlord->currentTeam);

    $response = $this->actingAs($landlord)->get(route('tenants'));

    $response
        ->assertOk()
        ->assertSee('Juana Dela Cruz');
});

test('a landlord can filter tenants by property or unit number', function () {
    $landlord = User::factory()->create();
    $property = Property::factory()->for($landlord->currentTeam)->create(['name' => 'Sunrise Apartments']);
    $unit = Unit::factory()->for($property)->create(['unit_number' => '204', 'status' => UnitStatus::Occupied]);

    $tenant = User::factory()->create(['name' => 'Juana Dela Cruz']);
    $landlord->currentTeam->members()->attach($tenant, ['role' => TeamRole::Tenant]);
    Lease::factory()->for($unit)->for($tenant, 'tenant')->create(['status' => LeaseStatus::Active]);

    $otherTenant = User::factory()->create(['name' => 'Pedro Reyes']);
    $landlord->currentTeam->members()->attach($otherTenant, ['role' => TeamRole::Tenant]);

    $this->actingAs($landlord);

    Livewire::test('pages::landlord.tenants')
        ->set('search', 'sunrise')
        ->assertSee('Juana Dela Cruz')
        ->assertDontSee('Pedro Reyes')
        ->set('search', '204')
        ->assertSee('Juana Dela Cruz')
        ->assertDontSee('Pedro Reyes');
});

test('searching tenants hides those that do not match', function () {
    $landlord = User::factory()->create();
    $tenant = User::factory()->create(['name' => 'Juana Dela Cruz']);
    $landlord->currentTeam->members()->attach($tenant, ['role' => TeamRole::Tenant]);

    $this->actingAs($landlord);

    Livewire::test('pages::landlord.tenants')
        ->set('search', 'no such tenant')
        ->assertDontSee('Juana Dela Cruz');
});

test('a landlord can assign a vacant unit to a tenant', function () {
    $landlord = User::factory()->create();
    $tenant = User::factory()->create();

    $landlord->currentTeam->members()->attach($tenant, ['role' => TeamRole::Tenant]);

    $property = Property::factory()->for($landlord->currentTeam)->create();
    $unit = Unit::factory()->for($property)->create(['status' => UnitStatus::Vacant, 'price' => 5000]);

    $this->actingAs($landlord);

    Livewire::test('pages::landlord.tenants')
        ->call('manageTenant', $tenant->id)
        ->set('unit_id', $unit->id)
        ->set('due_day', 5)
        ->call('saveUnitAssignment')
        ->assertHasNoErrors()
        ->assertSee('Unit '.$unit->unit_number);

    $lease = Lease::where('unit_id', $unit->id)->where('tenant_id', $tenant->id)->firstOrFail();

    $this->assertDatabaseHas('leases', [
        'unit_id' => $unit->id,
        'tenant_id' => $tenant->id,
        'status' => LeaseStatus::Active->value,
    ]);
    $this->assertDatabaseHas('lease_rents', [
        'lease_id' => $lease->id,
        'amount' => 5000,
    ]);

    expect($unit->fresh()->status)->toBe(UnitStatus::Occupied);
});

test('assigning a unit defaults billing timing to advance and lets the landlord switch it to arrears', function () {
    $landlord = User::factory()->create();
    $tenant = User::factory()->create();

    $landlord->currentTeam->members()->attach($tenant, ['role' => TeamRole::Tenant]);

    $property = Property::factory()->for($landlord->currentTeam)->create();
    $unit = Unit::factory()->for($property)->create(['status' => UnitStatus::Vacant, 'price' => 5000]);

    $this->actingAs($landlord);

    Livewire::test('pages::landlord.tenants')
        ->call('manageTenant', $tenant->id)
        ->assertSet('billing_timing', 'advance')
        ->set('unit_id', $unit->id)
        ->set('due_day', 5)
        ->set('billing_timing', 'arrears')
        ->call('saveUnitAssignment')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('leases', [
        'unit_id' => $unit->id,
        'tenant_id' => $tenant->id,
        'billing_timing' => BillingTiming::Arrears->value,
    ]);
});

test('opening a tenant with an existing lease prefills their current billing timing', function () {
    $landlord = User::factory()->create();
    $property = Property::factory()->for($landlord->currentTeam)->create();
    $unit = Unit::factory()->for($property)->create(['status' => UnitStatus::Occupied]);

    $tenant = User::factory()->create();
    $landlord->currentTeam->members()->attach($tenant, ['role' => TeamRole::Tenant]);
    Lease::factory()->for($unit)->for($tenant, 'tenant')->create([
        'status' => LeaseStatus::Active,
        'billing_timing' => BillingTiming::Arrears,
    ]);

    $this->actingAs($landlord);

    Livewire::test('pages::landlord.tenants')
        ->call('manageTenant', $tenant->id)
        ->assertSet('billing_timing', 'arrears');
});

test('a unit that does not allow multiple tenants is unavailable once occupied', function () {
    $landlord = User::factory()->create();
    $property = Property::factory()->for($landlord->currentTeam)->create();
    $unit = Unit::factory()->for($property)->create([
        'status' => UnitStatus::Occupied,
        'allows_multiple_tenants' => false,
    ]);
    Lease::factory()->for($unit)->create(['status' => LeaseStatus::Active]);

    $tenant = User::factory()->create();
    $landlord->currentTeam->members()->attach($tenant, ['role' => TeamRole::Tenant]);

    $this->actingAs($landlord);

    Livewire::test('pages::landlord.tenants')
        ->call('manageTenant', $tenant->id)
        ->assertDontSee('Unit '.$unit->unit_number);
});

test('a landlord can assign a second tenant to a unit that allows multiple tenants', function () {
    $landlord = User::factory()->create();
    $property = Property::factory()->for($landlord->currentTeam)->create();
    $unit = Unit::factory()->for($property)->create([
        'status' => UnitStatus::Occupied,
        'allows_multiple_tenants' => true,
        'tenant_limit' => 2,
        'price' => 4000,
    ]);
    $existingLease = Lease::factory()->for($unit)->create(['status' => LeaseStatus::Active]);
    $existingLease->rents()->create(['amount' => 4000, 'effective_date' => now()->subMonth()]);

    $tenant = User::factory()->create();
    $landlord->currentTeam->members()->attach($tenant, ['role' => TeamRole::Tenant]);

    $this->actingAs($landlord);

    Livewire::test('pages::landlord.tenants')
        ->call('manageTenant', $tenant->id)
        ->set('unit_id', $unit->id)
        ->set('due_day', 10)
        ->call('saveUnitAssignment')
        ->assertHasNoErrors();

    $newLease = Lease::where('unit_id', $unit->id)->where('tenant_id', $tenant->id)->firstOrFail();

    $this->assertDatabaseHas('leases', [
        'unit_id' => $unit->id,
        'tenant_id' => $tenant->id,
        'status' => LeaseStatus::Active->value,
    ]);
    $this->assertDatabaseCount('leases', 2);

    expect($newLease->currentRent->amount)->toEqual('2000.00')
        ->and($existingLease->refresh()->currentRent->amount)->toEqual('4000.00');

    $this->assertDatabaseHas('lease_rents', [
        'lease_id' => $existingLease->id,
        'amount' => 2000,
    ]);
});

test('a multi-tenant unit becomes unavailable once it reaches its tenant limit', function () {
    $landlord = User::factory()->create();
    $property = Property::factory()->for($landlord->currentTeam)->create();
    $unit = Unit::factory()->for($property)->create([
        'status' => UnitStatus::Occupied,
        'allows_multiple_tenants' => true,
        'tenant_limit' => 1,
    ]);
    Lease::factory()->for($unit)->create(['status' => LeaseStatus::Active]);

    $tenant = User::factory()->create();
    $landlord->currentTeam->members()->attach($tenant, ['role' => TeamRole::Tenant]);

    $this->actingAs($landlord);

    Livewire::test('pages::landlord.tenants')
        ->call('manageTenant', $tenant->id)
        ->assertDontSee('Unit '.$unit->unit_number);
});

test('assigning a tenant to a full unit is rejected even if submitted directly', function () {
    $landlord = User::factory()->create();
    $property = Property::factory()->for($landlord->currentTeam)->create();
    $unit = Unit::factory()->for($property)->create([
        'status' => UnitStatus::Occupied,
        'allows_multiple_tenants' => true,
        'tenant_limit' => 1,
    ]);
    Lease::factory()->for($unit)->create(['status' => LeaseStatus::Active]);

    $tenant = User::factory()->create();
    $landlord->currentTeam->members()->attach($tenant, ['role' => TeamRole::Tenant]);

    $this->actingAs($landlord);

    Livewire::test('pages::landlord.tenants')
        ->call('manageTenant', $tenant->id)
        ->set('unit_id', $unit->id)
        ->set('due_day', 10)
        ->call('saveUnitAssignment')
        ->assertForbidden();

    $this->assertDatabaseCount('leases', 1);
});

test('a landlord can move a tenant to a different unit, re-splitting rent on both sides', function () {
    $landlord = User::factory()->create();
    $property = Property::factory()->for($landlord->currentTeam)->create();

    $oldUnit = Unit::factory()->for($property)->create([
        'status' => UnitStatus::Occupied,
        'allows_multiple_tenants' => true,
        'tenant_limit' => 2,
        'price' => 4000,
    ]);
    $newUnit = Unit::factory()->for($property)->create([
        'status' => UnitStatus::Vacant,
        'price' => 6000,
    ]);

    $movingTenant = User::factory()->create();
    $landlord->currentTeam->members()->attach($movingTenant, ['role' => TeamRole::Tenant]);
    $movingLease = Lease::factory()->for($oldUnit)->for($movingTenant, 'tenant')->create(['status' => LeaseStatus::Active, 'due_day' => 5]);
    $movingLease->rents()->create(['amount' => 2000, 'effective_date' => now()]);

    $stayingTenant = User::factory()->create();
    $landlord->currentTeam->members()->attach($stayingTenant, ['role' => TeamRole::Tenant]);
    $stayingLease = Lease::factory()->for($oldUnit)->for($stayingTenant, 'tenant')->create(['status' => LeaseStatus::Active]);
    $stayingLease->rents()->create(['amount' => 2000, 'effective_date' => now()]);

    $this->actingAs($landlord);

    Livewire::test('pages::landlord.tenants')
        ->call('manageTenant', $movingTenant->id)
        ->set('unit_id', $newUnit->id)
        ->set('due_day', 15)
        ->call('saveUnitAssignment')
        ->assertHasNoErrors();

    expect($movingLease->fresh()->status)->toBe(LeaseStatus::Ended)
        ->and($movingLease->fresh()->end_date)->not->toBeNull()
        ->and($stayingLease->fresh()->currentRent->amount)->toEqual('2000.00')
        ->and($oldUnit->fresh()->status)->toBe(UnitStatus::Occupied);

    $this->assertDatabaseHas('lease_rents', [
        'lease_id' => $stayingLease->id,
        'amount' => 4000,
    ]);

    $newLease = Lease::where('unit_id', $newUnit->id)->where('tenant_id', $movingTenant->id)->firstOrFail();

    expect($newLease->status)->toBe(LeaseStatus::Active)
        ->and($newLease->due_day)->toBe(15)
        ->and($newLease->currentRent->amount)->toEqual('6000.00')
        ->and($newUnit->fresh()->status)->toBe(UnitStatus::Occupied);
});

test('a tenant can be removed from within the manage tenant modal', function () {
    $landlord = User::factory()->create();
    $property = Property::factory()->for($landlord->currentTeam)->create();
    $unit = Unit::factory()->for($property)->create([
        'status' => UnitStatus::Occupied,
        'price' => 5000,
    ]);

    $tenant = User::factory()->create();
    $landlord->currentTeam->members()->attach($tenant, ['role' => TeamRole::Tenant]);
    $lease = Lease::factory()->for($unit)->for($tenant, 'tenant')->create(['status' => LeaseStatus::Active]);
    $lease->rents()->create(['amount' => 5000, 'effective_date' => now()]);

    $this->actingAs($landlord);

    Livewire::test('pages::landlord.tenants')
        ->call('manageTenant', $tenant->id)
        ->call('confirmRemoveTenant', $lease->id)
        ->assertSet('showManageModal', false)
        ->call('removeTenant')
        ->assertHasNoErrors();

    expect($lease->fresh()->status)->toBe(LeaseStatus::Terminated)
        ->and($unit->fresh()->status)->toBe(UnitStatus::Vacant);
});

test('a landlord can remove a tenant, freeing the unit', function () {
    $landlord = User::factory()->create();
    $property = Property::factory()->for($landlord->currentTeam)->create();
    $unit = Unit::factory()->for($property)->create([
        'status' => UnitStatus::Occupied,
        'price' => 5000,
    ]);

    $tenant = User::factory()->create();
    $landlord->currentTeam->members()->attach($tenant, ['role' => TeamRole::Tenant]);
    $lease = Lease::factory()->for($unit)->for($tenant, 'tenant')->create(['status' => LeaseStatus::Active]);
    $lease->rents()->create(['amount' => 5000, 'effective_date' => now()]);

    $this->actingAs($landlord);

    Livewire::test('pages::landlord.tenants')
        ->call('confirmRemoveTenant', $lease->id)
        ->call('removeTenant')
        ->assertHasNoErrors();

    expect($lease->fresh()->status)->toBe(LeaseStatus::Terminated)
        ->and($lease->fresh()->end_date)->not->toBeNull()
        ->and($unit->fresh()->status)->toBe(UnitStatus::Vacant);
});

test('removing a tenant from a shared unit re-splits rent among those who remain', function () {
    $landlord = User::factory()->create();
    $property = Property::factory()->for($landlord->currentTeam)->create();
    $unit = Unit::factory()->for($property)->create([
        'status' => UnitStatus::Occupied,
        'allows_multiple_tenants' => true,
        'tenant_limit' => 2,
        'price' => 4000,
    ]);

    $leavingTenant = User::factory()->create();
    $landlord->currentTeam->members()->attach($leavingTenant, ['role' => TeamRole::Tenant]);
    $leavingLease = Lease::factory()->for($unit)->for($leavingTenant, 'tenant')->create(['status' => LeaseStatus::Active]);
    $leavingLease->rents()->create(['amount' => 2000, 'effective_date' => now()]);

    $stayingTenant = User::factory()->create();
    $landlord->currentTeam->members()->attach($stayingTenant, ['role' => TeamRole::Tenant]);
    $stayingLease = Lease::factory()->for($unit)->for($stayingTenant, 'tenant')->create(['status' => LeaseStatus::Active]);
    $stayingLease->rents()->create(['amount' => 2000, 'effective_date' => now()]);

    $this->actingAs($landlord);

    Livewire::test('pages::landlord.tenants')
        ->call('confirmRemoveTenant', $leavingLease->id)
        ->call('removeTenant')
        ->assertHasNoErrors();

    expect($leavingLease->fresh()->status)->toBe(LeaseStatus::Terminated)
        ->and($unit->fresh()->status)->toBe(UnitStatus::Occupied)
        ->and($stayingLease->fresh()->currentRent->amount)->toEqual('2000.00');

    $this->assertDatabaseHas('lease_rents', [
        'lease_id' => $stayingLease->id,
        'amount' => 4000,
    ]);
});

test('a landlord cannot remove a tenant belonging to another team', function () {
    $landlord = User::factory()->create();
    $otherLandlord = User::factory()->create();
    $otherProperty = Property::factory()->for($otherLandlord->currentTeam)->create();
    $otherUnit = Unit::factory()->for($otherProperty)->create(['status' => UnitStatus::Occupied]);

    $otherTenant = User::factory()->create();
    $otherLandlord->currentTeam->members()->attach($otherTenant, ['role' => TeamRole::Tenant]);
    $otherLease = Lease::factory()->for($otherUnit)->for($otherTenant, 'tenant')->create(['status' => LeaseStatus::Active]);

    $this->actingAs($landlord);

    $this->expectException(ModelNotFoundException::class);

    Livewire::test('pages::landlord.tenants')
        ->call('confirmRemoveTenant', $otherLease->id);
});
