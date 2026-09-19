<?php

use App\Enums\TeamRole;
use App\Enums\UnitStatus;
use App\Models\Property;
use App\Models\User;
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

test('a landlord sees their properties and units', function () {
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
        ->assertSee('Unit 101');
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
        ->call('addUnit')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('units', [
        'property_id' => $property->id,
        'unit_number' => '202',
    ]);
});
