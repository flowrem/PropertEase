<?php

use App\Enums\ItemCondition;
use App\Enums\LeaseStatus;
use App\Enums\TeamRole;
use App\Models\ConditionCheck;
use App\Models\ConditionCheckItem;
use App\Models\Lease;
use App\Models\Property;
use App\Models\Unit;
use App\Models\UnitItem;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

/**
 * A tenant with an active lease on the landlord's unit, and the move-in
 * check that lease claimed.
 *
 * @return array{0: User, 1: ConditionCheck}
 */
function tenantWithMoveInCheck(User $landlord, ?Unit $unit = null): array
{
    $tenant = User::factory()->create();
    $landlord->currentTeam->members()->attach($tenant, ['role' => TeamRole::Tenant]);
    $tenant->switchTeam($landlord->currentTeam);

    $unit ??= Unit::factory()->for(Property::factory()->for($landlord->currentTeam))->create();
    $lease = Lease::factory()->for($unit)->create(['tenant_id' => $tenant->id, 'status' => LeaseStatus::Active]);
    $check = ConditionCheck::factory()->claimedBy($lease)->create();
    ConditionCheckItem::factory()
        ->for($check, 'check')
        ->for(UnitItem::factory()->for($unit)->state(['name' => 'Faucet, kitchen']), 'unitItem')
        ->condition(ItemCondition::NeedsRepair)
        ->create(['remarks' => 'Drips slowly']);

    return [$tenant, $check];
}

test('a tenant sees the move-in checklist their lease claimed', function () {
    [$tenant] = tenantWithMoveInCheck(User::factory()->create());

    $this->actingAs($tenant)
        ->get(route('move-in-checklist'))
        ->assertOk()
        ->assertSee('Faucet, kitchen')
        ->assertSee('Needs repair')
        ->assertSee('Drips slowly')
        ->assertSee('I acknowledge this checklist');
});

test('a tenant can acknowledge their checklist once', function () {
    [$tenant, $check] = tenantWithMoveInCheck(User::factory()->create());

    $this->travelTo(now()->subDay());
    Livewire::actingAs($tenant)->test('pages::move-in-checklist')->call('acknowledge');
    $this->travelBack();

    $acknowledgedAt = $check->fresh()->tenant_acknowledged_at;

    Livewire::actingAs($tenant)->test('pages::move-in-checklist')
        ->assertSee('You acknowledged this checklist')
        ->call('acknowledge');

    expect($acknowledgedAt)->not->toBeNull()
        ->and($check->fresh()->tenant_acknowledged_at->equalTo($acknowledgedAt))->toBeTrue();
});

test('the dashboard asks a tenant to acknowledge their checklist until they do', function () {
    [$tenant, $check] = tenantWithMoveInCheck(User::factory()->create());

    $this->actingAs($tenant)->get(route('dashboard'))
        ->assertSee('Move-in checklist')
        ->assertSee('Please acknowledge');

    $check->acknowledge();

    $this->actingAs($tenant)->get(route('dashboard'))
        ->assertSee('Move-in checklist')
        ->assertDontSee('Please acknowledge');
});

test('a roommate sees their own checklist, not the other tenant\'s', function () {
    $landlord = User::factory()->create();
    [$tenant, $tenantsCheck] = tenantWithMoveInCheck($landlord);
    [$roommate, $roommatesCheck] = tenantWithMoveInCheck($landlord, $tenantsCheck->unit);

    Livewire::actingAs($roommate)->test('pages::move-in-checklist')->call('acknowledge');

    expect($roommatesCheck->fresh()->tenant_acknowledged_at)->not->toBeNull()
        ->and($tenantsCheck->fresh()->tenant_acknowledged_at)->toBeNull();
});

test('only the lease\'s tenant may acknowledge a move-in check', function () {
    $landlord = User::factory()->create();
    [$tenant, $check] = tenantWithMoveInCheck($landlord);
    [$otherTenant] = tenantWithMoveInCheck(User::factory()->create());

    expect(Gate::forUser($tenant)->allows('acknowledge', $check))->toBeTrue()
        ->and(Gate::forUser($otherTenant)->allows('acknowledge', $check))->toBeFalse()
        ->and(Gate::forUser($landlord)->allows('acknowledge', $check))->toBeFalse();
});

test('a tenant without a checklist is told so', function () {
    $landlord = User::factory()->create();
    $tenant = User::factory()->create();
    $landlord->currentTeam->members()->attach($tenant, ['role' => TeamRole::Tenant]);
    $tenant->switchTeam($landlord->currentTeam);

    Livewire::actingAs($tenant)->test('pages::move-in-checklist')
        ->assertSee('You are not renting a unit here right now.');

    Lease::factory()->for(Unit::factory()->for(Property::factory()->for($landlord->currentTeam)))
        ->create(['tenant_id' => $tenant->id, 'status' => LeaseStatus::Active]);

    Livewire::actingAs($tenant)->test('pages::move-in-checklist')
        ->assertSee('Your landlord has not recorded a move-in checklist for your unit.');
});
