<?php

use App\Enums\LeaseStatus;
use App\Enums\TeamRole;
use App\Enums\UnitStatus;
use App\Models\Lease;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Livewire\Livewire;

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
        ->assertSee(route('landlord.maintenance'), false);
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

test('a tenant can leave their unit', function () {
    $landlord = User::factory()->create();
    $property = Property::factory()->for($landlord->currentTeam)->create();
    $unit = Unit::factory()->for($property)->create([
        'status' => UnitStatus::Occupied,
        'price' => 5000,
    ]);

    $tenant = User::factory()->create();
    $landlord->currentTeam->members()->attach($tenant, ['role' => TeamRole::Tenant]);
    $tenant->switchTeam($landlord->currentTeam);
    $lease = Lease::factory()->for($unit)->for($tenant, 'tenant')->create(['status' => LeaseStatus::Active]);
    $lease->rents()->create(['amount' => 5000, 'effective_date' => now()]);

    $this->actingAs($tenant);

    Livewire::test('pages::dashboard')
        ->assertSee('Unit '.$unit->unit_number)
        ->call('confirmLeaveUnit')
        ->call('leaveUnit')
        ->assertHasNoErrors();

    expect($lease->fresh()->status)->toBe(LeaseStatus::Ended)
        ->and($lease->fresh()->end_date)->not->toBeNull()
        ->and($unit->fresh()->status)->toBe(UnitStatus::Vacant);
});
