<?php

use App\Enums\ConcernStatus;
use App\Enums\InvoiceStatus;
use App\Enums\LeaseStatus;
use App\Enums\TeamRole;
use App\Enums\UnitStatus;
use App\Models\Concern;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $user = User::factory()->create();

    $response = $this->get(route('properties'));

    $response->assertRedirect(route('login'));
});

test('tenants cannot access the properties page', function () {
    $landlord = User::factory()->create();
    $tenant = User::factory()->create();

    $landlord->currentTeam->members()->attach($tenant, ['role' => TeamRole::Tenant]);
    $tenant->switchTeam($landlord->currentTeam);

    $response = $this->actingAs($tenant)->get(route('properties'));

    $response->assertForbidden();
});

test('a landlord sees their properties but not their units until expanded', function () {
    $user = User::factory()->create();
    $property = Property::factory()->for($user->currentTeam)->create(['name' => 'Sunrise Apartments']);

    $property->units()->create([
        'unit_number' => '101',
        'bedrooms' => 2,
        'bathrooms' => 1,
        'status' => UnitStatus::Vacant,
    ]);

    $response = $this->actingAs($user)->get(route('properties'));

    $response
        ->assertOk()
        ->assertSee('Sunrise Apartments')
        ->assertDontSee('Unit 101');
});

test('a landlord can expand a property to reveal its units', function () {
    $user = User::factory()->create();
    $property = Property::factory()->for($user->currentTeam)->create();

    $property->units()->create([
        'unit_number' => '101',
        'bedrooms' => 2,
        'bathrooms' => 1,
        'status' => UnitStatus::Vacant,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::landlord.properties')
        ->assertDontSee('Unit 101')
        ->call('toggleProperty', $property->id)
        ->assertSee('Unit 101')
        ->call('toggleProperty', $property->id)
        ->assertDontSee('Unit 101');
});

test('an expanded unit shows its tenant and current invoice status', function () {
    $user = User::factory()->create();
    $property = Property::factory()->for($user->currentTeam)->create();
    $unit = Unit::factory()->for($property)->create(['status' => UnitStatus::Occupied]);
    $tenant = User::factory()->create(['name' => 'Juana Dela Cruz']);
    $lease = Lease::factory()->for($unit)->for($tenant, 'tenant')->create(['status' => LeaseStatus::Active]);

    Invoice::factory()->for($lease)->create([
        'status' => InvoiceStatus::Overdue,
        'due_date' => now()->subDays(3),
    ]);

    $this->actingAs($user);

    Livewire::test('pages::landlord.properties')
        ->call('toggleProperty', $property->id)
        ->assertSee('Juana Dela Cruz')
        ->assertSee('Overdue');
});

test('an expanded unit links to the inbox when it has open concerns', function () {
    $user = User::factory()->create();
    $property = Property::factory()->for($user->currentTeam)->create();
    $unit = Unit::factory()->for($property)->create(['status' => UnitStatus::Occupied]);
    $lease = Lease::factory()->for($unit)->create(['status' => LeaseStatus::Active]);

    Concern::factory()->for($lease)->create(['status' => ConcernStatus::Pending]);
    Concern::factory()->for($lease)->create(['status' => ConcernStatus::Resolved]);

    $this->actingAs($user);

    Livewire::test('pages::landlord.properties')
        ->call('toggleProperty', $property->id)
        ->assertSee('1 open concern')
        ->assertSee(route('inbox'));
});

test('a landlord can add a unit to an existing property', function () {
    $user = User::factory()->create();
    $property = Property::factory()->for($user->currentTeam)->create();

    $this->actingAs($user);

    Livewire::test('pages::landlord.properties')
        ->call('startAddingUnit', $property->id)
        ->set('unit_number', '202')
        ->set('bedrooms', 1)
        ->set('bathrooms', 1)
        ->set('price', '3500')
        ->call('addUnit')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('units', [
        'property_id' => $property->id,
        'unit_number' => '202',
        'allows_multiple_tenants' => false,
        'price' => 3500,
    ]);
});

test('a landlord can add a unit that allows multiple tenants', function () {
    $user = User::factory()->create();
    $property = Property::factory()->for($user->currentTeam)->create();

    $this->actingAs($user);

    Livewire::test('pages::landlord.properties')
        ->call('startAddingUnit', $property->id)
        ->set('unit_number', '203')
        ->set('bedrooms', 4)
        ->set('bathrooms', 2)
        ->set('occupancy', 'multiple')
        ->set('tenant_limit', 4)
        ->set('price', '4000')
        ->call('addUnit')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('units', [
        'property_id' => $property->id,
        'unit_number' => '203',
        'allows_multiple_tenants' => true,
        'tenant_limit' => 4,
    ]);
});

test('adding a unit with multiple occupancy requires a tenant limit', function () {
    $user = User::factory()->create();
    $property = Property::factory()->for($user->currentTeam)->create();

    $this->actingAs($user);

    Livewire::test('pages::landlord.properties')
        ->call('startAddingUnit', $property->id)
        ->set('unit_number', '204')
        ->set('bedrooms', 4)
        ->set('bathrooms', 2)
        ->set('occupancy', 'multiple')
        ->call('addUnit')
        ->assertHasErrors(['tenant_limit' => 'required']);
});

test('a landlord can edit a unit\'s details', function () {
    $user = User::factory()->create();
    $property = Property::factory()->for($user->currentTeam)->create();
    $unit = Unit::factory()->for($property)->create([
        'unit_number' => '101',
        'bedrooms' => 1,
        'bathrooms' => 1,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::landlord.properties')
        ->call('startEditingUnit', $unit->id)
        ->set('unit_number', '101-A')
        ->set('bedrooms', 3)
        ->set('bathrooms', 2)
        ->call('updateUnit')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('units', [
        'id' => $unit->id,
        'unit_number' => '101-A',
        'bedrooms' => 3,
        'bathrooms' => 2,
    ]);
});

test('a landlord can switch a unit to allow multiple tenants', function () {
    $user = User::factory()->create();
    $property = Property::factory()->for($user->currentTeam)->create();
    $unit = Unit::factory()->for($property)->create(['allows_multiple_tenants' => false]);

    $this->actingAs($user);

    Livewire::test('pages::landlord.properties')
        ->call('startEditingUnit', $unit->id)
        ->assertSet('occupancy', 'single')
        ->set('occupancy', 'multiple')
        ->set('tenant_limit', 3)
        ->call('updateUnit')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('units', [
        'id' => $unit->id,
        'allows_multiple_tenants' => true,
        'tenant_limit' => 3,
    ]);
});

test('an expanded unit lists every tenant when it allows multiple tenants', function () {
    $user = User::factory()->create();
    $property = Property::factory()->for($user->currentTeam)->create();
    $unit = Unit::factory()->for($property)->create([
        'status' => UnitStatus::Occupied,
        'allows_multiple_tenants' => true,
    ]);

    $tenantA = User::factory()->create(['name' => 'Juana Dela Cruz']);
    $tenantB = User::factory()->create(['name' => 'Maria Santos']);
    Lease::factory()->for($unit)->for($tenantA, 'tenant')->create(['status' => LeaseStatus::Active]);
    Lease::factory()->for($unit)->for($tenantB, 'tenant')->create(['status' => LeaseStatus::Active]);

    $this->actingAs($user);

    Livewire::test('pages::landlord.properties')
        ->call('toggleProperty', $property->id)
        ->assertSee('Juana Dela Cruz')
        ->assertSee('Maria Santos');
});

test('changing a unit\'s price schedules the new rent for next cycle without changing what is due now', function () {
    $user = User::factory()->create();
    $property = Property::factory()->for($user->currentTeam)->create();
    $unit = Unit::factory()->for($property)->create([
        'status' => UnitStatus::Occupied,
        'allows_multiple_tenants' => true,
        'tenant_limit' => 2,
        'price' => 2000,
    ]);

    $tenantA = User::factory()->create();
    $tenantB = User::factory()->create();
    $leaseA = Lease::factory()->for($unit)->for($tenantA, 'tenant')->create(['status' => LeaseStatus::Active]);
    $leaseB = Lease::factory()->for($unit)->for($tenantB, 'tenant')->create(['status' => LeaseStatus::Active]);
    $leaseA->rents()->create(['amount' => 1000, 'effective_date' => now()->subMonth()]);
    $leaseB->rents()->create(['amount' => 1000, 'effective_date' => now()->subMonth()]);

    $this->actingAs($user);

    Livewire::test('pages::landlord.properties')
        ->call('startEditingUnit', $unit->id)
        ->set('price', '4000')
        ->call('updateUnit')
        ->assertHasNoErrors();

    expect($leaseA->refresh()->currentRent->amount)->toEqual('1000.00')
        ->and($leaseB->refresh()->currentRent->amount)->toEqual('1000.00');

    $scheduledRentA = $leaseA->rents()->where('amount', 2000)->firstOrFail();
    $scheduledRentB = $leaseB->rents()->where('amount', 2000)->firstOrFail();

    expect($scheduledRentA->effective_date->toDateString())->toBe($leaseA->nextRentEffectiveDate()->toDateString())
        ->and($scheduledRentB->effective_date->toDateString())->toBe($leaseB->nextRentEffectiveDate()->toDateString());
});

test('a landlord cannot edit a unit belonging to another team', function () {
    $user = User::factory()->create();
    $otherLandlord = User::factory()->create();
    $otherProperty = Property::factory()->for($otherLandlord->currentTeam)->create();
    $otherUnit = Unit::factory()->for($otherProperty)->create();

    $this->actingAs($user);

    $this->expectException(ModelNotFoundException::class);

    Livewire::test('pages::landlord.properties')
        ->call('startEditingUnit', $otherUnit->id);
});
