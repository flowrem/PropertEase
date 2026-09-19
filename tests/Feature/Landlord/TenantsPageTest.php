<?php

use App\Enums\LeaseStatus;
use App\Enums\TeamRole;
use App\Enums\UnitStatus;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $user = User::factory()->create();

    $response = $this->get(route('tenants'));

    $response->assertRedirect(route('login'));
});

test('tenants cannot access the tenants page', function () {
    $landlord = User::factory()->create();
    $tenant = User::factory()->create();

    $landlord->currentTeam->members()->attach($tenant, ['role' => TeamRole::Tenant]);
    $tenant->switchTeam($landlord->currentTeam);

    $response = $this->actingAs($tenant)->get(route('tenants'));

    $response->assertForbidden();
});

test('a landlord sees tenants attached to their team', function () {
    $landlord = User::factory()->create();
    $tenant = User::factory()->create(['name' => 'Juana Dela Cruz']);

    $landlord->currentTeam->members()->attach($tenant, ['role' => TeamRole::Tenant]);
    $landlord->switchTeam($landlord->currentTeam);

    $response = $this->actingAs($landlord)->get(route('tenants'));

    $response
        ->assertOk()
        ->assertSee('Juana Dela Cruz');
});

test('a landlord can assign a vacant unit to a tenant', function () {
    $landlord = User::factory()->create();
    $tenant = User::factory()->create();

    $landlord->currentTeam->members()->attach($tenant, ['role' => TeamRole::Tenant]);

    $property = Property::factory()->for($landlord->currentTeam)->create();
    $unit = Unit::factory()->for($property)->create(['status' => UnitStatus::Vacant]);

    $this->actingAs($landlord);

    Livewire::test('pages::landlord.tenants')
        ->call('startAssigning', $tenant->id)
        ->set('unit_id', $unit->id)
        ->set('amount', '5000')
        ->set('due_day', 5)
        ->call('assignUnit')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('leases', [
        'unit_id' => $unit->id,
        'tenant_id' => $tenant->id,
        'status' => LeaseStatus::Active->value,
    ]);

    expect($unit->fresh()->status)->toBe(UnitStatus::Occupied);
});
