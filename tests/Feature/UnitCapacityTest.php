<?php

use App\Enums\LeaseStatus;
use App\Enums\PropertyType;
use App\Enums\UnitStatus;
use App\Models\Lease;
use App\Models\Property;
use App\Models\Unit;

test('the most tenants a unit fits depends on its floor area and property type', function (float $floorArea, PropertyType $type, int $expected) {
    expect(Unit::maxCapacityFor($floorArea, $type))->toBe($expected);
})->with([
    'apartment, 24 m²' => [24, PropertyType::Apartment, 4],
    'apartment, 23.9 m²' => [23.9, PropertyType::Apartment, 3],
    'condominium, 6 m²' => [6, PropertyType::Condominium, 1],
    'dormitory, 24 m²' => [24, PropertyType::Dormitory, 6],
    'boarding house, 10 m²' => [10, PropertyType::BoardingHouse, 2],
    'always at least one' => [6, PropertyType::Dormitory, 1],
    'never more than twenty' => [300, PropertyType::Dormitory, 20],
]);

test('the most bedrooms a unit fits depends on its floor area and bathrooms', function (float $floorArea, int $bathrooms, int $expected) {
    expect(Unit::maxBedroomsFor($floorArea, $bathrooms))->toBe($expected);
})->with([
    '24 m², 1 bathroom' => [24, 1, 3],
    '24 m², 0 bathrooms still reserves room for one' => [24, 0, 3],
    'a huge unit is still capped at the configured ceiling' => [300, 0, 10],
    'never below zero' => [6, 10, 0],
]);

test('bedrooms can never claim the entire floor area and leave no room for a bathroom', function () {
    expect(Unit::maxBedroomsFor(30, bathrooms: 0))->toBe(4)
        ->and(Unit::minimumFloorAreaFor(bedrooms: 5, bathrooms: 0))->toBe(31.2);
});

test('the most bathrooms a unit fits depends on its floor area and bedrooms', function (float $floorArea, int $bedrooms, int $expected) {
    expect(Unit::maxBathroomsFor($floorArea, $bedrooms))->toBe($expected);
})->with([
    '13 m², 1 bedroom is capped at one more than the bedrooms' => [13, 1, 2],
    '24 m², 3 bedrooms is capped at 4 even though the area alone fits 5' => [24, 3, 4],
    'a huge unit is still capped at the configured ceiling' => [300, 15, 10],
    'never below zero' => [6, 10, 0],
]);

function sharedApartmentUnit(?float $floorArea, int $tenantLimit, int $activeTenants): Unit
{
    $unit = Unit::factory()
        ->for(Property::factory()->state(['type' => PropertyType::Apartment]))
        ->create([
            'status' => UnitStatus::Occupied,
            'allows_multiple_tenants' => true,
            'tenant_limit' => $tenantLimit,
            'floor_area_sqm' => $floorArea,
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
