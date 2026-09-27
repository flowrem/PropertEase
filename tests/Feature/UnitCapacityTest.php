<?php

use App\Enums\LeaseStatus;
use App\Enums\PropertyType;
use App\Enums\UnitStatus;
use App\Models\Amenity;
use App\Models\Lease;
use App\Models\Property;
use App\Models\Unit;

test('the most tenants a studio fits depends on its floor area and property type', function (float $floorArea, PropertyType $type, int $expected) {
    expect(Unit::maxCapacityFor($floorArea, $type, bedrooms: 0))->toBe($expected);
})->with([
    'apartment, 24 m²' => [24, PropertyType::Apartment, 4],
    'apartment, 23.9 m²' => [23.9, PropertyType::Apartment, 3],
    'condominium, 6 m²' => [6, PropertyType::Condominium, 1],
    'dormitory, 24 m²' => [24, PropertyType::Dormitory, 6],
    'boarding house, 10 m²' => [10, PropertyType::BoardingHouse, 2],
    'always at least one' => [6, PropertyType::Dormitory, 1],
    'never more than twenty' => [300, PropertyType::Dormitory, 20],
]);

test('a unit with bedrooms fits only as many tenants as its bedrooms sleep', function (float $floorArea, PropertyType $type, int $bedrooms, int $expected) {
    expect(Unit::maxCapacityFor($floorArea, $type, $bedrooms))->toBe($expected);
})->with([
    'apartment, 1 bedroom in 24 m² sleeps 2, not 4' => [24, PropertyType::Apartment, 1, 2],
    'apartment, 2 bedrooms in 24 m²' => [24, PropertyType::Apartment, 2, 4],
    'apartment, 3 bedrooms is still capped by the floor area' => [24, PropertyType::Apartment, 3, 4],
    'dormitory, 1 bedroom sleeps 4' => [24, PropertyType::Dormitory, 1, 4],
    'boarding house, 2 bedrooms sleep 8' => [70, PropertyType::BoardingHouse, 2, 8],
]);

test('a unit with beds fits as many tenants as its beds sleep, whatever its bedrooms', function (float $floorArea, PropertyType $type, int $bedrooms, int $bedSpaces, int $expected) {
    expect(Unit::maxCapacityFor($floorArea, $type, $bedrooms, $bedSpaces))->toBe($expected);
})->with([
    'a single bed in a 1-bedroom apartment sleeps 1, not 2' => [24, PropertyType::Apartment, 1, 1, 1],
    'two double decks in a 1-bedroom dormitory sleep 4' => [24, PropertyType::Dormitory, 1, 4, 4],
    'a bedspace studio with three double decks sleeps 6' => [30, PropertyType::BoardingHouse, 0, 6, 6],
    'beds are still capped by the floor area' => [24, PropertyType::Apartment, 2, 8, 4],
]);

test('the most bedrooms a unit fits depends on its floor area and bathrooms', function (float $floorArea, int $bathrooms, int $expected) {
    expect(Unit::maxBedroomsFor($floorArea, $bathrooms))->toBe($expected);
})->with([
    '24 m², 1 bathroom' => [24, 1, 2],
    '24 m², 0 bathrooms still reserves room for one' => [24, 0, 2],
    'too small for a bedroom and a common area' => [13, 1, 0],
    'a huge unit is still capped at the configured ceiling' => [300, 0, 10],
    'never below zero' => [6, 10, 0],
]);

test('bedrooms can never claim the floor area needed for a bathroom and a common area', function () {
    expect(Unit::maxBedroomsFor(30, bathrooms: 0))->toBe(3)
        ->and(Unit::minimumFloorAreaFor(bedrooms: 4, bathrooms: 0))->toBe(31.2)
        ->and(Unit::minimumFloorAreaFor(bedrooms: 0, bathrooms: 1))->toBe(6.0);
});

test('the most bathrooms a unit fits depends on its floor area and bedrooms', function (float $floorArea, int $bedrooms, int $expected) {
    expect(Unit::maxBathroomsFor($floorArea, $bedrooms))->toBe($expected);
})->with([
    '16 m², 1 bedroom is capped at one more than the bedrooms' => [16, 1, 2],
    '30 m², 3 bedrooms is capped at 4 even though the area alone fits 5' => [30, 3, 4],
    'a studio needs no common area set aside' => [8.4, 0, 1],
    'a huge unit is still capped at the configured ceiling' => [300, 15, 10],
    'never below zero' => [6, 10, 0],
]);

function sharedApartmentUnit(?float $floorArea, int $tenantLimit, int $activeTenants, int $bedrooms = 0): Unit
{
    $unit = Unit::factory()
        ->for(Property::factory()->state(['type' => PropertyType::Apartment]))
        ->create([
            'status' => UnitStatus::Occupied,
            'allows_multiple_tenants' => true,
            'tenant_limit' => $tenantLimit,
            'floor_area_sqm' => $floorArea,
            'bedrooms' => $bedrooms,
        ]);

    Lease::factory()->for($unit)->count($activeTenants)->create(['status' => LeaseStatus::Active]);

    return $unit->fresh();
}

test('the floor area caps a shared unit\'s tenant limit', function (int $activeTenants, bool $hasRoom) {
    $unit = sharedApartmentUnit(floorArea: 18, tenantLimit: 5, activeTenants: $activeTenants);

    expect($unit->capacity())->toBe(3)
        ->and($unit->hasRoomForAnotherTenant())->toBe($hasRoom)
        ->and(Unit::hasRoom()->whereKey($unit->id)->exists())->toBe($hasRoom);
})->with([
    'two of three taken' => [2, true],
    'all three taken' => [3, false],
    'over the cap' => [4, false],
]);

test('the bedrooms cap a shared unit\'s tenant limit', function (int $activeTenants, bool $hasRoom) {
    $unit = sharedApartmentUnit(floorArea: 24, tenantLimit: 4, activeTenants: $activeTenants, bedrooms: 1);

    expect($unit->capacity())->toBe(2)
        ->and($unit->hasRoomForAnotherTenant())->toBe($hasRoom)
        ->and(Unit::hasRoom()->whereKey($unit->id)->exists())->toBe($hasRoom);
})->with([
    'one of two taken' => [1, true],
    'both taken' => [2, false],
]);

test('the beds cap a shared unit\'s tenant limit, in place of its bedrooms', function (int $activeTenants, bool $hasRoom) {
    $unit = sharedApartmentUnit(floorArea: 30, tenantLimit: 5, activeTenants: $activeTenants, bedrooms: 2);
    $unit->amenities()->attach([
        Amenity::query()->whereNull('team_id')->where('name', 'Double deck')->value('id') => ['quantity' => 1],
        Amenity::query()->whereNull('team_id')->where('name', 'Single bed')->value('id') => ['quantity' => 1],
        Amenity::query()->whereNull('team_id')->where('name', 'Television')->value('id') => ['quantity' => 2],
    ]);

    expect($unit->capacity())->toBe(3)
        ->and(Unit::withBedSpaces()->find($unit->id)->bedSpaces())->toBe(3)
        ->and($unit->hasRoomForAnotherTenant())->toBe($hasRoom)
        ->and(Unit::hasRoom()->whereKey($unit->id)->exists())->toBe($hasRoom);
})->with([
    'two of three taken' => [2, true],
    'all three taken' => [3, false],
]);

test('a unit without a floor area keeps the limit its landlord chose', function () {
    $unit = sharedApartmentUnit(floorArea: null, tenantLimit: 5, activeTenants: 4);

    expect($unit->maxCapacity())->toBeNull()
        ->and($unit->capacity())->toBe(5)
        ->and($unit->hasRoomForAnotherTenant())->toBeTrue()
        ->and(Unit::hasRoom()->whereKey($unit->id)->exists())->toBeTrue();
});

test('a vacant single-tenant unit has room whatever its size', function () {
    $unit = Unit::factory()->create(['status' => UnitStatus::Vacant, 'floor_area_sqm' => 6]);

    expect($unit->hasRoomForAnotherTenant())->toBeTrue()
        ->and(Unit::hasRoom()->whereKey($unit->id)->exists())->toBeTrue();
});
