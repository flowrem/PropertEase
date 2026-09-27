<?php

use App\Enums\ConcernStatus;
use App\Enums\InvoiceStatus;
use App\Enums\LeaseStatus;
use App\Enums\ListingStatus;
use App\Enums\PropertyType;
use App\Enums\ReservationStatus;
use App\Enums\TeamRole;
use App\Enums\UnitStatus;
use App\Models\Concern;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Unit;
use App\Models\UnitListing;
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
        ->set('form.unit_number', '202')
        ->set('form.floor_level', '2nd floor')
        ->set('form.floor_area_sqm', '18.5')
        ->set('form.bedrooms', 1)
        ->set('form.bathrooms', 1)
        ->set('form.price', '3500')
        ->call('addUnit')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('units', [
        'property_id' => $property->id,
        'unit_number' => '202',
        'floor_level' => '2nd floor',
        'floor_area_sqm' => 18.5,
        'allows_multiple_tenants' => false,
        'price' => 3500,
    ]);
});

test('adding a unit asks for confirmation before saving', function () {
    $user = User::factory()->create();
    $property = Property::factory()->for($user->currentTeam)->create();

    $this->actingAs($user);

    Livewire::test('pages::landlord.properties')
        ->call('startAddingUnit', $property->id)
        ->set('form.unit_number', '202')
        ->set('form.floor_level', '2nd floor')
        ->set('form.floor_area_sqm', '18')
        ->set('form.price', '3500')
        ->call('reviewNewUnit')
        ->assertHasNoErrors()
        ->assertSet('showConfirmUnitModal', true)
        ->assertSee('These details cannot be changed once the unit is saved.');

    expect($property->units()->count())->toBe(0);
});

test('a landlord can add a unit that allows multiple tenants', function () {
    $user = User::factory()->create();
    $property = Property::factory()->for($user->currentTeam)->create(['type' => PropertyType::Apartment]);

    $this->actingAs($user);

    Livewire::test('pages::landlord.properties')
        ->call('startAddingUnit', $property->id)
        ->set('form.unit_number', '203')
        ->set('form.floor_level', 'Ground floor')
        ->set('form.floor_area_sqm', '30')
        ->set('form.bedrooms', 4)
        ->set('form.bathrooms', 2)
        ->set('form.occupancy', 'multiple')
        ->set('form.tenant_limit', 4)
        ->set('form.price', '4000')
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
        ->set('form.unit_number', '204')
        ->set('form.bedrooms', 4)
        ->set('form.bathrooms', 2)
        ->set('form.occupancy', 'multiple')
        ->call('addUnit')
        ->assertHasErrors(['form.tenant_limit' => 'required']);
});

test('editing a unit changes its name but never its locked details', function () {
    $user = User::factory()->create();
    $property = Property::factory()->for($user->currentTeam)->create();
    $unit = Unit::factory()->for($property)->create([
        'unit_number' => '101',
        'floor_level' => 'Ground floor',
        'bedrooms' => 1,
        'bathrooms' => 1,
        'floor_area_sqm' => 20,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::landlord.properties')
        ->call('startEditingUnit', $unit->id)
        ->set('form.unit_number', 'Sampaguita 2A')
        ->set('form.floor_level', '5th floor')
        ->set('form.bedrooms', 3)
        ->set('form.bathrooms', 2)
        ->set('form.floor_area_sqm', '250')
        ->call('updateUnit')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('units', [
        'id' => $unit->id,
        'unit_number' => 'Sampaguita 2A',
        'floor_level' => 'Ground floor',
        'bedrooms' => 1,
        'bathrooms' => 1,
        'floor_area_sqm' => 20,
    ]);
});

test('an older unit without a floor area can have its details completed once', function () {
    $user = User::factory()->create();
    $property = Property::factory()->for($user->currentTeam)->create();
    $unit = Unit::factory()->for($property)->withoutFloorArea()->create(['floor_level' => '2nd', 'bedrooms' => 0]);

    $this->actingAs($user);

    Livewire::test('pages::landlord.properties')
        ->call('startEditingUnit', $unit->id)
        ->call('updateUnit')
        ->assertHasErrors(['form.floor_area_sqm' => 'required', 'form.floor_level'])
        ->set('form.floor_level', '2nd floor')
        ->set('form.floor_area_sqm', '22')
        ->set('form.bedrooms', 2)
        ->set('form.bathrooms', 1)
        ->call('updateUnit')
        ->assertHasNoErrors();

    expect($unit->refresh())
        ->floor_level->toBe('2nd floor')
        ->bedrooms->toBe(2)
        ->floor_area_sqm->toEqual('22.0')
        ->hasLockedDetails()->toBeTrue();

    Livewire::test('pages::landlord.properties')
        ->call('startEditingUnit', $unit->id)
        ->set('form.floor_area_sqm', '90')
        ->call('updateUnit')
        ->assertHasNoErrors();

    expect($unit->refresh()->floor_area_sqm)->toEqual('22.0');
});

test('unit details outside the allowed limits are rejected', function (array $input, string $field) {
    $user = User::factory()->create();
    $property = Property::factory()->for($user->currentTeam)->create();

    $this->actingAs($user);

    Livewire::test('pages::landlord.properties')
        ->call('startAddingUnit', $property->id)
        ->set([
            'form.unit_number' => '101',
            'form.floor_level' => 'Ground floor',
            'form.floor_area_sqm' => '24',
            'form.bedrooms' => 1,
            'form.bathrooms' => 1,
            'form.price' => '5000',
            ...collect($input)->mapWithKeys(fn (mixed $value, string $key) => ["form.{$key}" => $value])->all(),
        ])
        ->call('addUnit')
        ->assertHasErrors(["form.{$field}"]);

    expect($property->units()->count())->toBe(0);
})->with([
    'floor area below the minimum' => [['floor_area_sqm' => '5', 'bedrooms' => 0, 'bathrooms' => 0], 'floor_area_sqm'],
    'floor area above the maximum' => [['floor_area_sqm' => '301'], 'floor_area_sqm'],
    'floor area with two decimals' => [['floor_area_sqm' => '24.25'], 'floor_area_sqm'],
    'a floor not on the list' => [['floor_level' => '1st floor'], 'floor_level'],
    'rent below the minimum' => [['price' => '499'], 'price'],
    'rent above the maximum' => [['price' => '200001'], 'price'],
    'a name longer than 40 characters' => [['unit_number' => str_repeat('A', 41)], 'unit_number'],
]);

test('shrinking an existing unit\'s floor area below what its rooms need is rejected', function () {
    $user = User::factory()->create();
    $property = Property::factory()->for($user->currentTeam)->create();
    $unit = Unit::factory()->for($property)->withoutFloorArea()->create(['bedrooms' => 2, 'bathrooms' => 1]);

    $this->actingAs($user);

    Livewire::test('pages::landlord.properties')
        ->call('startEditingUnit', $unit->id)
        ->set('form.floor_area_sqm', '10')
        ->call('updateUnit')
        ->assertHasErrors(['form.floor_area_sqm']);
});

test('typing more bedrooms or bathrooms than the floor area fits snaps the value back down', function () {
    $user = User::factory()->create();
    $property = Property::factory()->for($user->currentTeam)->create(['type' => PropertyType::Apartment]);

    $this->actingAs($user);

    Livewire::test('pages::landlord.properties')
        ->call('startAddingUnit', $property->id)
        ->set('form.floor_area_sqm', '24')
        ->set('form.bedrooms', 15)
        ->assertSet('form.bedrooms', (string) Unit::maxBedroomsFor(24, bathrooms: 1))
        ->set('form.bathrooms', 15)
        ->assertSet('form.bathrooms', (string) Unit::maxBathroomsFor(24, bedrooms: (int) Unit::maxBedroomsFor(24, bathrooms: 1)));
});

test('bedrooms and bathrooms never exceed the configured ceiling, even in a huge unit', function () {
    $user = User::factory()->create();
    $property = Property::factory()->for($user->currentTeam)->create(['type' => PropertyType::Apartment]);

    $this->actingAs($user);

    Livewire::test('pages::landlord.properties')
        ->call('startAddingUnit', $property->id)
        ->set('form.floor_area_sqm', '300')
        ->set('form.bedrooms', 50)
        ->assertSet('form.bedrooms', (string) config('occuplace.units.bedrooms.max'))
        ->set('form.bathrooms', 50)
        ->assertSet('form.bathrooms', (string) config('occuplace.units.bathrooms.max'));
});

test('a bedroom count too large for PHP\'s integer type is clamped instead of crashing', function () {
    $user = User::factory()->create();
    $property = Property::factory()->for($user->currentTeam)->create(['type' => PropertyType::Apartment]);

    $this->actingAs($user);

    Livewire::test('pages::landlord.properties')
        ->call('startAddingUnit', $property->id)
        ->set('form.floor_area_sqm', '150')
        ->set('form.bedrooms', '99999999999999999999999999999999999999999999999')
        ->assertSet('form.bedrooms', (string) Unit::maxBedroomsFor(150, bathrooms: 1));
});

test('a bathroom count too large for PHP\'s integer type is clamped instead of crashing', function () {
    $user = User::factory()->create();
    $property = Property::factory()->for($user->currentTeam)->create(['type' => PropertyType::Apartment]);

    $this->actingAs($user);

    Livewire::test('pages::landlord.properties')
        ->call('startAddingUnit', $property->id)
        ->set('form.floor_area_sqm', '150')
        ->set('form.bathrooms', '99999999999999999999999999999999999999999999999')
        ->assertSet('form.bathrooms', (string) Unit::maxBathroomsFor(150, bedrooms: 1));
});

test('a shared unit cannot hold more tenants than its floor area allows', function () {
    $user = User::factory()->create();
    $property = Property::factory()->for($user->currentTeam)->create(['type' => PropertyType::Apartment]);

    $this->actingAs($user);

    $component = Livewire::test('pages::landlord.properties')
        ->call('startAddingUnit', $property->id)
        ->set([
            'form.unit_number' => '101',
            'form.floor_level' => 'Ground floor',
            'form.floor_area_sqm' => '18',
            'form.bedrooms' => 1,
            'form.bathrooms' => 1,
            'form.price' => '6000',
            'form.occupancy' => 'multiple',
        ])
        ->assertSee('This floor area fits up to 3 tenants.');

    $component->set('form.tenant_limit', 4)->call('addUnit')->assertHasErrors(['form.tenant_limit']);
    $component->set('form.tenant_limit', 3)->call('addUnit')->assertHasNoErrors();
});

test('a unit too small for two tenants cannot be shared', function () {
    $user = User::factory()->create();
    $property = Property::factory()->for($user->currentTeam)->create(['type' => PropertyType::Apartment]);

    $this->actingAs($user);

    Livewire::test('pages::landlord.properties')
        ->call('startAddingUnit', $property->id)
        ->set([
            'form.unit_number' => '101',
            'form.floor_level' => 'Ground floor',
            'form.floor_area_sqm' => '11',
            'form.bedrooms' => 0,
            'form.bathrooms' => 1,
            'form.price' => '3000',
            'form.occupancy' => 'multiple',
            'form.tenant_limit' => 2,
        ])
        ->call('addUnit')
        ->assertHasErrors(['form.occupancy', 'form.tenant_limit']);
});

test('dormitories fit more tenants in the same floor area', function () {
    $user = User::factory()->create();
    $property = Property::factory()->for($user->currentTeam)->create(['type' => PropertyType::Dormitory]);

    $this->actingAs($user);

    Livewire::test('pages::landlord.properties')
        ->call('startAddingUnit', $property->id)
        ->set('form.floor_area_sqm', '18')
        ->set('form.occupancy', 'multiple')
        ->assertSee('This floor area fits up to 4 tenants.');
});

test('a shared unit\'s limit cannot drop below the tenants and reservations holding it', function () {
    $user = User::factory()->create();
    $property = Property::factory()->for($user->currentTeam)->create();
    $unit = Unit::factory()->for($property)->create([
        'status' => UnitStatus::Occupied,
        'allows_multiple_tenants' => true,
        'tenant_limit' => 4,
    ]);
    Lease::factory()->for($unit)->count(2)->create(['status' => LeaseStatus::Active]);
    Reservation::factory()->for($unit)->status(ReservationStatus::Approved)->create();

    $this->actingAs($user);

    Livewire::test('pages::landlord.properties')
        ->call('startEditingUnit', $unit->id)
        ->set('form.tenant_limit', 2)
        ->call('updateUnit')
        ->assertHasErrors(['form.tenant_limit'])
        ->set('form.occupancy', 'single')
        ->call('updateUnit')
        ->assertHasErrors(['form.occupancy'])
        ->set('form.occupancy', 'multiple')
        ->set('form.tenant_limit', 3)
        ->call('updateUnit')
        ->assertHasNoErrors();

    expect($unit->refresh()->tenant_limit)->toBe(3);
});

test('a unit that was never used can be deleted', function () {
    $user = User::factory()->create();
    $property = Property::factory()->for($user->currentTeam)->create();
    $unit = Unit::factory()->for($property)->create();
    UnitListing::factory()->for($unit)->create();

    $this->actingAs($user);

    Livewire::test('pages::landlord.properties')
        ->call('confirmDeleteUnit', $unit->id)
        ->assertSet('showDeleteUnitModal', true)
        ->call('deleteUnit');

    expect(Unit::find($unit->id))->toBeNull();
});

test('a unit that was used cannot be deleted', function (string $use) {
    $user = User::factory()->create();
    $property = Property::factory()->for($user->currentTeam)->create();
    $unit = Unit::factory()->for($property)->create();

    match ($use) {
        'lease' => Lease::factory()->for($unit)->create(['status' => LeaseStatus::Ended]),
        'reservation' => Reservation::factory()->for($unit)->status(ReservationStatus::Rejected)->create(),
        'listing' => UnitListing::factory()->for($unit)->rejected()->create(),
    };

    $this->actingAs($user);

    Livewire::test('pages::landlord.properties')
        ->call('confirmDeleteUnit', $unit->id)
        ->assertSet('showDeleteUnitModal', false)
        ->set('deletingUnitId', $unit->id)
        ->call('deleteUnit');

    expect(Unit::find($unit->id))->not->toBeNull();
})->with([
    'an ended lease' => ['lease'],
    'a rejected reservation' => ['reservation'],
    'a listing sent for review' => ['listing'],
]);

test('a landlord cannot delete a unit belonging to another team', function () {
    $user = User::factory()->create();
    $otherUnit = Unit::factory()->for(Property::factory()->for(User::factory()->create()->currentTeam))->create();

    $this->actingAs($user);

    Livewire::test('pages::landlord.properties')
        ->set('deletingUnitId', $otherUnit->id)
        ->call('deleteUnit');
})->throws(ModelNotFoundException::class);

test('a landlord can edit a property\'s name and address', function () {
    $user = User::factory()->create();
    $property = Property::factory()->for($user->currentTeam)->create(['type' => PropertyType::Dormitory]);

    $this->actingAs($user);

    Livewire::test('pages::landlord.properties')
        ->call('startEditingProperty', $property->id)
        ->set('property_name', 'NU Lipa Residences')
        ->set('address_line', 'Tubigan Street')
        ->set('map_url', 'https://maps.google.com/?q=lipa')
        ->call('updateProperty')
        ->assertHasNoErrors();

    expect($property->refresh())
        ->name->toBe('NU Lipa Residences')
        ->address_line->toBe('Tubigan Street')
        ->map_url->toBe('https://maps.google.com/?q=lipa')
        ->type->toBe(PropertyType::Dormitory);
});

test('changing a property\'s address sends its approved listings back to review', function () {
    $user = User::factory()->create();
    $property = Property::factory()->for($user->currentTeam)->create();
    $approved = UnitListing::factory()->for(Unit::factory()->for($property))->approved()->create();
    $draft = UnitListing::factory()->for(Unit::factory()->for($property))->create();

    $this->actingAs($user);

    Livewire::test('pages::landlord.properties')
        ->call('startEditingProperty', $property->id)
        ->set('city', 'Lipa')
        ->call('updateProperty');

    expect($approved->refresh()->status)->toBe(ListingStatus::PendingReview)
        ->and($draft->refresh()->status)->toBe(ListingStatus::Draft);
});

test('renaming a property keeps its approved listings live', function () {
    $user = User::factory()->create();
    $property = Property::factory()->for($user->currentTeam)->create();
    $approved = UnitListing::factory()->for(Unit::factory()->for($property))->approved()->create();

    $this->actingAs($user);

    Livewire::test('pages::landlord.properties')
        ->call('startEditingProperty', $property->id)
        ->set('property_name', 'A new name')
        ->call('updateProperty');

    expect($approved->refresh()->status)->toBe(ListingStatus::Approved);
});

test('a landlord cannot edit another team\'s property', function () {
    $user = User::factory()->create();
    $otherProperty = Property::factory()->for(User::factory()->create()->currentTeam)->create();

    $this->actingAs($user);

    Livewire::test('pages::landlord.properties')
        ->call('startEditingProperty', $otherProperty->id);
})->throws(ModelNotFoundException::class);

test('a landlord can switch a unit to allow multiple tenants', function () {
    $user = User::factory()->create();
    $property = Property::factory()->for($user->currentTeam)->create();
    $unit = Unit::factory()->for($property)->create(['allows_multiple_tenants' => false]);

    $this->actingAs($user);

    Livewire::test('pages::landlord.properties')
        ->call('startEditingUnit', $unit->id)
        ->assertSet('form.occupancy', 'single')
        ->set('form.occupancy', 'multiple')
        ->set('form.tenant_limit', 3)
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
        ->set('form.price', '4000')
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
