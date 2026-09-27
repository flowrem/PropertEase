<?php

use App\Enums\LeaseStatus;
use App\Enums\PropertyType;
use App\Enums\UnitStatus;
use App\Models\Amenity;
use App\Models\Lease;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

function defaultAmenity(string $name): Amenity
{
    return Amenity::query()->whereNull('team_id')->where('name', $name)->firstOrFail();
}

/**
 * @param  array<int|string, int|string>  $quantities
 */
function addUnitWithAmenities(User $user, Property $property, array $amenityIds, array $quantities = []): Testable
{
    return Livewire::actingAs($user)
        ->test('pages::landlord.properties')
        ->call('startAddingUnit', $property->id)
        ->set([
            'form.unit_number' => '101',
            'form.floor_level' => 'Ground floor',
            'form.floor_area_sqm' => '30',
            'form.bedrooms' => 2,
            'form.bathrooms' => 1,
            'form.price' => '6000',
        ])
        ->set('form.amenityIds', array_map('strval', $amenityIds))
        ->set('form.amenityQuantities', $quantities + array_fill_keys($amenityIds, '1'))
        ->call('addUnit');
}

test('a landlord can save a unit with the amenities it comes with', function () {
    $user = User::factory()->create();
    $property = Property::factory()->for($user->currentTeam)->create();
    $doubleDeck = defaultAmenity('Double deck');
    $television = defaultAmenity('Television');
    $ownAmenity = Amenity::factory()->for($user->currentTeam)->create(['name' => 'Rooftop access']);

    addUnitWithAmenities($user, $property, [$doubleDeck->id, $television->id, $ownAmenity->id], [$doubleDeck->id => '2'])
        ->assertHasNoErrors();

    $amenities = Unit::query()->sole()->amenities->pluck('pivot.quantity', 'name');

    expect($amenities->all())->toEqual(['Double deck' => 2, 'Television' => 1, 'Rooftop access' => 1]);
});

test('ticking an amenity starts its quantity at one', function () {
    $user = User::factory()->create();
    $property = Property::factory()->for($user->currentTeam)->create();
    $fan = defaultAmenity('Electric fan');

    Livewire::actingAs($user)
        ->test('pages::landlord.properties')
        ->call('startAddingUnit', $property->id)
        ->set('form.amenityIds', [(string) $fan->id])
        ->assertSet("form.amenityQuantities.{$fan->id}", '1')
        ->assertSee('Electric fan');
});

test('another team\'s custom amenity cannot be put on a unit', function () {
    $user = User::factory()->create();
    $property = Property::factory()->for($user->currentTeam)->create();
    $otherTeamsAmenity = Amenity::factory()->create();

    addUnitWithAmenities($user, $property, [$otherTeamsAmenity->id])
        ->assertHasErrors(['form.amenityIds.0']);

    expect(Unit::query()->count())->toBe(0);
});

test('a deactivated amenity cannot be added to a unit', function () {
    $user = User::factory()->create();
    $property = Property::factory()->for($user->currentTeam)->create();
    $retired = Amenity::factory()->for($user->currentTeam)->inactive()->create();

    addUnitWithAmenities($user, $property, [$retired->id])
        ->assertHasErrors(['form.amenityIds.0']);
});

test('an amenity quantity must stay within bounds', function (string $quantity) {
    $user = User::factory()->create();
    $property = Property::factory()->for($user->currentTeam)->create();
    $chair = defaultAmenity('Chair');

    addUnitWithAmenities($user, $property, [$chair->id], [$chair->id => $quantity])
        ->assertHasErrors(["form.amenityQuantities.{$chair->id}"]);
})->with([
    'zero' => '0',
    'above the maximum' => '21',
    'not a number' => 'two',
]);

test('editing a unit replaces its amenities but keeps a deactivated one it already has', function () {
    $user = User::factory()->create();
    $property = Property::factory()->for($user->currentTeam)->create();
    $unit = Unit::factory()->for($property)->create();
    $retired = Amenity::factory()->for($user->currentTeam)->inactive()->create();
    $fan = defaultAmenity('Electric fan');
    $unit->amenities()->attach([$retired->id => ['quantity' => 1], $fan->id => ['quantity' => 2]]);
    $aircon = defaultAmenity('Air conditioner');

    $component = Livewire::actingAs($user)
        ->test('pages::landlord.properties')
        ->call('startEditingUnit', $unit->id);

    expect($component->get('form.amenityIds'))->toEqualCanonicalizing([(string) $retired->id, (string) $fan->id]);

    $component->set('form.amenityIds', [(string) $retired->id, (string) $aircon->id])
        ->call('updateUnit')
        ->assertHasNoErrors();

    expect($unit->amenities()->pluck('name')->sort()->values()->all())->toBe(['Air conditioner', $retired->name]);
});

test('the beds ticked on the form decide how many tenants a shared unit fits', function () {
    $user = User::factory()->create();
    $property = Property::factory()->for($user->currentTeam)->create(['type' => PropertyType::Apartment]);
    $doubleDeck = defaultAmenity('Double deck');
    $singleBed = defaultAmenity('Single bed');

    Livewire::actingAs($user)
        ->test('pages::landlord.properties')
        ->call('startAddingUnit', $property->id)
        ->set([
            'form.floor_area_sqm' => '30',
            'form.bedrooms' => 2,
            'form.occupancy' => 'multiple',
            'form.amenityIds' => [(string) $doubleDeck->id, (string) $singleBed->id],
        ])
        ->set("form.amenityQuantities.{$doubleDeck->id}", '2')
        ->assertSee('The beds sleep up to 5 tenants.')
        ->set('form.tenant_limit', 6)
        ->assertSet('form.tenant_limit', '5');
});

test('the form says only the ticked beds are counted, and which ones', function () {
    $user = User::factory()->create();
    $property = Property::factory()->for($user->currentTeam)->create(['type' => PropertyType::Apartment]);
    $doubleDeck = defaultAmenity('Double deck');

    Livewire::actingAs($user)
        ->test('pages::landlord.properties')
        ->call('startAddingUnit', $property->id)
        ->assertDontSee('Counting only the ticked beds')
        ->set('form.amenityIds', [(string) $doubleDeck->id, (string) defaultAmenity('Single bed')->id, (string) defaultAmenity('Television')->id])
        ->set("form.amenityQuantities.{$doubleDeck->id}", '2')
        ->assertSee('Counting only the ticked beds: 2 double decks and 1 single bed sleep 5 people.');
});

/**
 * The Properties page adding a shared 40 m² apartment (room for 6 by floor
 * area, 4 by its 2 bedrooms) with no beds ticked yet.
 */
function sharedUnitBeingAdded(User $user): Testable
{
    $property = Property::factory()->for($user->currentTeam)->create(['type' => PropertyType::Apartment]);

    return Livewire::actingAs($user)
        ->test('pages::landlord.properties')
        ->call('startAddingUnit', $property->id)
        ->set([
            'form.floor_area_sqm' => '40',
            'form.bedrooms' => 2,
            'form.occupancy' => 'multiple',
        ]);
}

test('the tenant limit follows the beds up and down while it sits at the maximum', function () {
    $user = User::factory()->create();
    $doubleDeck = defaultAmenity('Double deck');

    sharedUnitBeingAdded($user)
        ->set('form.amenityIds', [(string) $doubleDeck->id])
        ->assertSet('form.tenant_limit', '2')
        ->set("form.amenityQuantities.{$doubleDeck->id}", '3')
        ->assertSet('form.tenant_limit', '6')
        ->set("form.amenityQuantities.{$doubleDeck->id}", '2')
        ->assertSet('form.tenant_limit', '4');
});

test('a tenant limit set lower on purpose stays when beds are added, and only comes down when beds are removed', function () {
    $user = User::factory()->create();
    $doubleDeck = defaultAmenity('Double deck');

    sharedUnitBeingAdded($user)
        ->set('form.amenityIds', [(string) $doubleDeck->id])
        ->set("form.amenityQuantities.{$doubleDeck->id}", '2')
        ->set('form.tenant_limit', '3')
        ->set("form.amenityQuantities.{$doubleDeck->id}", '3')
        ->assertSet('form.tenant_limit', '3')
        ->set("form.amenityQuantities.{$doubleDeck->id}", '1')
        ->assertSet('form.tenant_limit', '2');
});

test('a unit whose only bed is a single bed cannot be shared', function () {
    $user = User::factory()->create();
    $property = Property::factory()->for($user->currentTeam)->create(['type' => PropertyType::Apartment]);
    $singleBed = defaultAmenity('Single bed');

    Livewire::actingAs($user)
        ->test('pages::landlord.properties')
        ->call('startAddingUnit', $property->id)
        ->set([
            'form.unit_number' => '101',
            'form.floor_level' => 'Ground floor',
            'form.floor_area_sqm' => '30',
            'form.bedrooms' => 2,
            'form.bathrooms' => 1,
            'form.price' => '6000',
            'form.occupancy' => 'multiple',
            'form.tenant_limit' => 2,
            'form.amenityIds' => [(string) $singleBed->id],
        ])
        ->call('addUnit')
        ->assertHasErrors(['form.occupancy', 'form.tenant_limit']);
});

/**
 * A shared apartment with two double decks (sleeping 4) and the given
 * number of active tenants.
 */
function sharedUnitWithTwoDoubleDecks(User $user, int $activeTenants): Unit
{
    $unit = Unit::factory()
        ->for(Property::factory()->for($user->currentTeam)->state(['type' => PropertyType::Apartment]))
        ->create([
            'status' => UnitStatus::Occupied,
            'floor_area_sqm' => 30,
            'bedrooms' => 2,
            'allows_multiple_tenants' => true,
            'tenant_limit' => 4,
        ]);
    $unit->amenities()->attach(defaultAmenity('Double deck')->id, ['quantity' => 2]);
    Lease::factory()->for($unit)->count($activeTenants)->create(['status' => LeaseStatus::Active]);

    return $unit;
}

test('removing beds is refused when fewer would not sleep the tenants already there', function () {
    $user = User::factory()->create();
    $unit = sharedUnitWithTwoDoubleDecks($user, activeTenants: 3);
    $doubleDeck = defaultAmenity('Double deck');

    Livewire::actingAs($user)
        ->test('pages::landlord.properties')
        ->call('startEditingUnit', $unit->id)
        ->set("form.amenityQuantities.{$doubleDeck->id}", '1')
        ->set('form.tenant_limit', '3')
        ->call('updateUnit')
        ->assertHasErrors(['form.amenityIds']);

    expect($unit->amenities()->sole()->pivot->quantity)->toBe(2);
});

test('removing beds is allowed while the rest still sleep everyone there', function () {
    $user = User::factory()->create();
    $unit = sharedUnitWithTwoDoubleDecks($user, activeTenants: 2);
    $doubleDeck = defaultAmenity('Double deck');

    Livewire::actingAs($user)
        ->test('pages::landlord.properties')
        ->call('startEditingUnit', $unit->id)
        ->set("form.amenityQuantities.{$doubleDeck->id}", '1')
        ->set('form.tenant_limit', '2')
        ->call('updateUnit')
        ->assertHasNoErrors();

    expect($unit->fresh()->capacity())->toBe(2);
});

test('the setup wizard saves a new unit\'s amenities', function () {
    $user = User::factory()->create();
    $property = Property::factory()->for($user->currentTeam)->create();
    $bed = defaultAmenity('Single bed');

    Livewire::actingAs($user)
        ->test('pages::landlord.setup')
        ->set('propertyId', $property->id)
        ->set('step', 2)
        ->set([
            'form.unit_number' => '101',
            'form.floor_level' => 'Ground floor',
            'form.floor_area_sqm' => '20',
            'form.bedrooms' => 1,
            'form.bathrooms' => 1,
            'form.price' => '4000',
            'form.amenityIds' => [(string) $bed->id],
        ])
        ->call('addUnit')
        ->assertHasNoErrors();

    expect(Unit::query()->sole()->amenities->pluck('name')->all())->toBe(['Single bed']);
});
