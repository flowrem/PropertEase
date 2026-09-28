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
        ->test('pages::landlord.unit-edit', ['unit' => $unit->id]);

    expect($component->get('form.amenityIds'))->toEqualCanonicalizing([(string) $retired->id, (string) $fan->id]);

    $component->set('form.amenityIds', [(string) $retired->id, (string) $aircon->id])
        ->call('updateUnit')
        ->assertHasNoErrors();

    expect($unit->amenities()->pluck('name')->all())->toEqualCanonicalizing(['Air conditioner', $retired->name]);
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
        ->assertSee('Counting only the beds you checked: 2 double decks and 1 single bed sleep 5 people.');
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
        ->test('pages::landlord.unit-edit', ['unit' => $unit->id])
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
        ->test('pages::landlord.unit-edit', ['unit' => $unit->id])
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

/**
 * Add a unit of the given size to a new property of the given type, with
 * the given amenities and quantities (amenity name => quantity).
 *
 * @param  array{floor_area_sqm: string, bedrooms: int, bathrooms: int}  $size
 * @param  array<string, string>  $quantities
 */
function addSizedUnitWithAmenities(User $user, PropertyType $type, array $size, array $quantities): Testable
{
    $property = Property::factory()->for($user->currentTeam)->create(['type' => $type]);
    $ids = collect($quantities)->keys()->mapWithKeys(fn (string $name): array => [$name => (string) (Amenity::query()->where('name', $name)->firstOrFail()->id)]);

    return Livewire::actingAs($user)
        ->test('pages::landlord.properties')
        ->call('startAddingUnit', $property->id)
        ->set([
            'form.unit_number' => '101',
            'form.floor_level' => 'Ground floor',
            'form.floor_area_sqm' => $size['floor_area_sqm'],
            'form.bedrooms' => $size['bedrooms'],
            'form.bathrooms' => $size['bathrooms'],
            'form.price' => '5000',
        ])
        ->set('form.amenityIds', $ids->values()->all())
        ->set('form.amenityQuantities', $ids->flip()->map(fn (string $name): string => $quantities[$name])->all())
        ->call('addUnit');
}

test('beds cannot sleep more tenants than the floor area fits', function (string $doubleDecks, bool $accepted) {
    $user = User::factory()->create();
    $doubleDeck = defaultAmenity('Double deck');

    // 24 m² dormitory at 4 m² per tenant fits 6 tenants, so 3 double decks.
    $component = addSizedUnitWithAmenities($user, PropertyType::Dormitory, ['floor_area_sqm' => '24', 'bedrooms' => 2, 'bathrooms' => 2], ['Double deck' => $doubleDecks]);

    $accepted
        ? $component->assertHasNoErrors()
        : $component->assertHasErrors(["form.amenityQuantities.{$doubleDeck->id}"]);
})->with([
    '3 double decks' => ['3', true],
    '4 double decks' => ['4', false],
    '13 double decks' => ['13', false],
]);

test('beds cannot cover more than half of the sleeping area', function (string $singleBeds, bool $accepted) {
    $user = User::factory()->create();
    $singleBed = defaultAmenity('Single bed');

    // 20 m² less 6 m² common area and 2 bathrooms leaves 11.6 m²; beds may
    // cover half, 5.8 m², which fits 3 single beds of 1.7 m² (tenants fit 5).
    $component = addSizedUnitWithAmenities($user, PropertyType::Dormitory, ['floor_area_sqm' => '20', 'bedrooms' => 1, 'bathrooms' => 2], ['Single bed' => $singleBeds]);

    $accepted
        ? $component->assertHasNoErrors()
        : $component->assertHasErrors(["form.amenityQuantities.{$singleBed->id}"]);
})->with([
    '3 single beds' => ['3', true],
    '4 single beds' => ['4', false],
]);

test('each bed\'s limit leaves room for the other beds ticked', function () {
    $user = User::factory()->create();

    // 6 tenants fit: 2 double decks sleep 4, leaving room for 2 single beds.
    addSizedUnitWithAmenities($user, PropertyType::Dormitory, ['floor_area_sqm' => '24', 'bedrooms' => 2, 'bathrooms' => 2], ['Double deck' => '2', 'Single bed' => '3'])
        ->assertHasErrors([
            'form.amenityQuantities.'.defaultAmenity('Double deck')->id,
            'form.amenityQuantities.'.defaultAmenity('Single bed')->id,
        ]);
});

test('other amenities are limited by the unit, its rooms, bathrooms or tenants', function (string $name, string $allowed, string $tooMany) {
    $user = User::factory()->create();
    $amenity = defaultAmenity($name);

    // A 30 m² apartment with 2 bedrooms, 1 bathroom and 2 double decks (4 tenants).
    $size = ['floor_area_sqm' => '30', 'bedrooms' => 2, 'bathrooms' => 1];

    addSizedUnitWithAmenities($user, PropertyType::Apartment, $size, ['Double deck' => '2', $name => $tooMany])
        ->assertHasErrors(["form.amenityQuantities.{$amenity->id}"]);

    addSizedUnitWithAmenities(User::factory()->create(), PropertyType::Apartment, $size, ['Double deck' => '2', $name => $allowed])
        ->assertHasNoErrors();
})->with([
    'one refrigerator per unit' => ['Refrigerator', '1', '2'],
    'one air conditioner per room' => ['Air conditioner', '3', '4'],
    'one water heater per bathroom' => ['Water heater', '1', '2'],
    'one wardrobe per tenant' => ['Wardrobe', '4', '5'],
    'two chairs per tenant' => ['Chair', '8', '9'],
]);

test('a yes-or-no amenity has no quantity box and cannot be listed twice', function () {
    $user = User::factory()->create();
    $wifi = defaultAmenity('Wi-Fi');

    addSizedUnitWithAmenities($user, PropertyType::Apartment, ['floor_area_sqm' => '30', 'bedrooms' => 2, 'bathrooms' => 1], ['Wi-Fi' => '2'])
        ->assertHasErrors(["form.amenityQuantities.{$wifi->id}"])
        ->assertDontSeeHtml("form.amenityQuantities.{$wifi->id}");
});

test('a landlord\'s own amenity is limited to one per tenant the unit fits', function () {
    $user = User::factory()->create();
    $ownAmenity = Amenity::factory()->for($user->currentTeam)->create(['name' => 'Rooftop locker']);

    addSizedUnitWithAmenities($user, PropertyType::Apartment, ['floor_area_sqm' => '30', 'bedrooms' => 2, 'bathrooms' => 1], ['Double deck' => '2', 'Rooftop locker' => '5'])
        ->assertHasErrors(["form.amenityQuantities.{$ownAmenity->id}"]);
});

test('a quantity typed past the limit snaps back to it and the form shows the limit', function () {
    $user = User::factory()->create();
    $property = Property::factory()->for($user->currentTeam)->create(['type' => PropertyType::Dormitory]);
    $doubleDeck = defaultAmenity('Double deck');

    Livewire::actingAs($user)
        ->test('pages::landlord.properties')
        ->call('startAddingUnit', $property->id)
        ->set([
            'form.floor_area_sqm' => '24',
            'form.bedrooms' => 2,
            'form.bathrooms' => 2,
            'form.amenityIds' => [(string) $doubleDeck->id],
        ])
        ->set("form.amenityQuantities.{$doubleDeck->id}", '13')
        ->assertSet("form.amenityQuantities.{$doubleDeck->id}", '3')
        ->assertSee('Up to 3');
});

test('each quantity limit says why in a sentence, and per-tenant items follow the beds checked', function () {
    $user = User::factory()->create();
    $property = Property::factory()->for($user->currentTeam)->create(['type' => PropertyType::Apartment]);
    $chair = defaultAmenity('Chair');
    $singleBed = defaultAmenity('Single bed');

    $component = Livewire::actingAs($user)
        ->test('pages::landlord.properties')
        ->call('startAddingUnit', $property->id)
        ->set([
            'form.floor_area_sqm' => '35',
            'form.bedrooms' => 3,
            'form.bathrooms' => 2,
        ])
        ->set('form.amenityIds', [(string) $chair->id])
        ->assertSee('Up to 10')
        ->assertSee('2 for each tenant. This 35 m² unit fits 5 people.')
        ->set('form.amenityIds', [(string) $chair->id, (string) $singleBed->id])
        ->assertSee('Up to 2')
        ->assertSee('2 for each tenant. The beds you checked sleep 1 person.')
        ->assertSee('Up to 5')
        ->assertSee('This 35 m² unit fits 5 people.');

    $component->set("form.amenityQuantities.{$singleBed->id}", '3')
        ->assertSee('Up to 6')
        ->assertSee('2 for each tenant. The beds you checked sleep 3 people.');
});

test('unticking an amenity clears its quantity error', function () {
    $user = User::factory()->create();
    $doubleDeck = defaultAmenity('Double deck');

    addSizedUnitWithAmenities($user, PropertyType::Dormitory, ['floor_area_sqm' => '24', 'bedrooms' => 2, 'bathrooms' => 2], ['Double deck' => '13'])
        ->assertHasErrors(["form.amenityQuantities.{$doubleDeck->id}"])
        ->set('form.amenityIds', [])
        ->assertHasNoErrors()
        ->assertSet('form.amenityQuantities', []);
});

test('an amenity a unit can only have one of asks for no quantity', function () {
    $user = User::factory()->create();
    $property = Property::factory()->for($user->currentTeam)->create(['type' => PropertyType::Apartment]);
    $refrigerator = defaultAmenity('Refrigerator');
    $chair = defaultAmenity('Chair');

    Livewire::actingAs($user)
        ->test('pages::landlord.properties')
        ->call('startAddingUnit', $property->id)
        ->set(['form.floor_area_sqm' => '30', 'form.bedrooms' => 2, 'form.bathrooms' => 1])
        ->set('form.amenityIds', [(string) $refrigerator->id, (string) $chair->id])
        ->assertDontSeeHtml("form.amenityQuantities.{$refrigerator->id}")
        ->assertSeeHtml("form.amenityQuantities.{$chair->id}");
});
