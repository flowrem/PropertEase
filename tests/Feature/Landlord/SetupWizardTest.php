<?php

use App\Enums\TeamRole;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $user = User::factory()->create();

    $response = $this->get(route('setup'));

    $response->assertRedirect(route('login'));
});

test('tenants cannot access the setup wizard', function () {
    $landlord = User::factory()->create();
    $tenant = User::factory()->create();

    $landlord->currentTeam->members()->attach($tenant, ['role' => TeamRole::Tenant]);
    $tenant->switchTeam($landlord->currentTeam);

    $response = $this->actingAs($tenant)->get(route('setup'));

    $response->assertForbidden();
});

test('a landlord can create a property in step one', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::landlord.setup')
        ->set('name', 'Sunrise Apartments')
        ->set('type', 'apartment')
        ->set('address_line', '123 Main St')
        ->set('city', 'Quezon City')
        ->set('province', 'Metro Manila')
        ->set('postal_code', '1100')
        ->call('createProperty')
        ->assertHasNoErrors()
        ->assertSet('step', 2);

    $this->assertDatabaseHas('properties', [
        'team_id' => $user->currentTeam->id,
        'name' => 'Sunrise Apartments',
    ]);
});

test('a landlord can add a unit in step two', function () {
    $user = User::factory()->create();
    $property = Property::factory()->for($user->currentTeam)->create();

    $this->actingAs($user);

    Livewire::test('pages::landlord.setup')
        ->set('propertyId', $property->id)
        ->set('step', 2)
        ->set('unit_number', '101')
        ->set('bedrooms', 2)
        ->set('bathrooms', 1)
        ->call('addUnit')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('units', [
        'property_id' => $property->id,
        'unit_number' => '101',
    ]);
});

test('a landlord can invite a tenant in step three', function () {
    Notification::fake();

    $user = User::factory()->create();
    $property = Property::factory()->for($user->currentTeam)->create();

    $this->actingAs($user);

    Livewire::test('pages::landlord.setup')
        ->set('propertyId', $property->id)
        ->set('step', 3)
        ->set('inviteEmail', 'tenant@example.com')
        ->call('sendInvite')
        ->assertHasNoErrors()
        ->assertSet('step', 4);

    $this->assertDatabaseHas('team_invitations', [
        'team_id' => $user->currentTeam->id,
        'email' => 'tenant@example.com',
        'role' => TeamRole::Tenant->value,
    ]);
});

test('a landlord can skip the invite step', function () {
    $user = User::factory()->create();
    $property = Property::factory()->for($user->currentTeam)->create();

    $this->actingAs($user);

    Livewire::test('pages::landlord.setup')
        ->set('propertyId', $property->id)
        ->set('step', 3)
        ->call('skipInvite')
        ->assertSet('step', 4);
});
