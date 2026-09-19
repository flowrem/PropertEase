<?php

use App\Enums\TeamRole;
use App\Models\Concern;
use App\Models\Lease;
use App\Models\Property;
use App\Models\Team;
use App\Models\Unit;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $user = User::factory()->create();

    $response = $this->get(route('inbox'));

    $response->assertRedirect(route('login'));
});

test('tenants cannot access the inbox', function () {
    $landlord = User::factory()->create();
    $tenant = User::factory()->create();

    $landlord->currentTeam->members()->attach($tenant, ['role' => TeamRole::Tenant]);
    $tenant->switchTeam($landlord->currentTeam);

    $response = $this->actingAs($tenant)->get(route('inbox'));

    $response->assertForbidden();
});

test('a landlord only sees concerns from their own team', function () {
    $landlord = User::factory()->create();
    $property = Property::factory()->for($landlord->currentTeam)->create();
    $unit = Unit::factory()->for($property)->create();
    $lease = Lease::factory()->for($unit)->create();
    Concern::factory()->for($lease)->create(['title' => 'Leaking faucet in unit']);

    $otherTeam = Team::factory()->create();
    $otherProperty = Property::factory()->for($otherTeam)->create();
    $otherUnit = Unit::factory()->for($otherProperty)->create();
    $otherLease = Lease::factory()->for($otherUnit)->create();
    Concern::factory()->for($otherLease)->create(['title' => 'Noise complaint from another building']);

    $landlord->switchTeam($landlord->currentTeam);

    $response = $this->actingAs($landlord)->get(route('inbox'));

    $response
        ->assertOk()
        ->assertSee('Leaking faucet in unit')
        ->assertDontSee('Noise complaint from another building');
});
