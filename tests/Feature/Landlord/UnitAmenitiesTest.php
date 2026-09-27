<?php

use App\Models\Amenity;
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
