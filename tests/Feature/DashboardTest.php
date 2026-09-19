<?php

use App\Enums\TeamRole;
use App\Models\Property;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $user = User::factory()->create();

    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('a landlord with no properties is redirected to the setup wizard', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertRedirect(route('setup'));
});

test('a landlord with a property sees the landlord dashboard', function () {
    $user = User::factory()->create();
    Property::factory()->for($user->currentTeam)->create();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response
        ->assertOk()
        ->assertSee(route('properties'), false)
        ->assertSee(route('tenants'), false)
        ->assertSee(route('inbox'), false);
});

test('a tenant sees the tenant dashboard', function () {
    $landlord = User::factory()->create();
    $tenant = User::factory()->create();

    $landlord->currentTeam->members()->attach($tenant, ['role' => TeamRole::Tenant]);
    $tenant->switchTeam($landlord->currentTeam);

    $response = $this->actingAs($tenant)->get(route('dashboard'));

    $response
        ->assertOk()
        ->assertSee(route('billing'), false)
        ->assertSee(route('maintenance'), false)
        ->assertSee(route('complaints'), false)
        ->assertSee(route('announcements'), false);
});
