<?php

use App\Enums\ItemCondition;
use App\Enums\LeaseStatus;
use App\Enums\TeamRole;
use App\Enums\UnitStatus;
use App\Models\ConditionCheck;
use App\Models\ConditionCheckItem;
use App\Models\Lease;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * @return array{0: User, 1: User, 2: Unit}
 */
function moveInSetup(array $unitAttributes = []): array
{
    $landlord = User::factory()->create();
    $tenant = User::factory()->create();
    $landlord->currentTeam->members()->attach($tenant, ['role' => TeamRole::Tenant]);

    $unit = Unit::factory()
        ->for(Property::factory()->for($landlord->currentTeam))
        ->create(['status' => UnitStatus::Vacant, 'price' => 5000, ...$unitAttributes]);

    return [$landlord, $tenant, $unit];
}

function assignTenant(User $actingAs, User $tenant, Unit $unit): Testable
{
    return Livewire::actingAs($actingAs)
        ->test('pages::landlord.tenants')
        ->call('manageTenant', $tenant->id)
        ->set('unit_id', $unit->id);
}

function checkWith(Unit $unit, ItemCondition $condition, array $attributes = []): ConditionCheck
{
    $check = ConditionCheck::factory()->for($unit)->create($attributes);
    ConditionCheckItem::factory()->for($check, 'check')->condition($condition)->create();

    return $check;
}

test('a tenant cannot be assigned to a unit without a move-in check', function () {
    [$landlord, $tenant, $unit] = moveInSetup();

    assignTenant($landlord, $tenant, $unit)
        ->assertSee('This unit has no move-in check since its last tenant left.')
        ->call('saveUnitAssignment')
        ->assertHasErrors(['unit_id']);

    expect(Lease::query()->exists())->toBeFalse();
});

test('a move-in check with an item not working or missing blocks the assignment', function (ItemCondition $condition) {
    [$landlord, $tenant, $unit] = moveInSetup();
    checkWith($unit, $condition);

    assignTenant($landlord, $tenant, $unit)
        ->call('saveUnitAssignment')
        ->assertHasErrors(['unit_id']);

    expect(Lease::query()->exists())->toBeFalse();
})->with([
    'not working' => ItemCondition::NotWorking,
    'missing' => ItemCondition::Missing,
]);

test('a move-in check from before the last tenant left no longer counts', function () {
    [$landlord, $tenant, $unit] = moveInSetup();
    checkWith($unit, ItemCondition::Working, ['checked_at' => now()->subDays(10)]);
    Lease::factory()->for($unit)->create(['status' => LeaseStatus::Ended, 'end_date' => now()->subDays(2)]);

    assignTenant($landlord, $tenant, $unit)
        ->call('saveUnitAssignment')
        ->assertHasErrors(['unit_id']);
});

test('a clean move-in check lets the tenant move in and is claimed by their lease', function (ItemCondition $condition) {
    [$landlord, $tenant, $unit] = moveInSetup();
    $check = checkWith($unit, $condition);

    assignTenant($landlord, $tenant, $unit)
        ->assertSee('The tenant will be asked to acknowledge it.')
        ->call('saveUnitAssignment')
        ->assertHasNoErrors();

    $lease = Lease::query()->sole();

    expect($check->fresh()->lease_id)->toBe($lease->id)
        ->and($lease->move_in_override_reason)->toBeNull();
})->with([
    'good as new' => ItemCondition::GoodAsNew,
    'working' => ItemCondition::Working,
    'needs repair' => ItemCondition::NeedsRepair,
]);

test('each tenant moving into a shared unit needs a move-in check of their own', function () {
    [$landlord, $tenant, $unit] = moveInSetup(['allows_multiple_tenants' => true, 'tenant_limit' => 2, 'floor_area_sqm' => 30, 'bedrooms' => 1]);
    $roommate = User::factory()->create();
    $landlord->currentTeam->members()->attach($roommate, ['role' => TeamRole::Tenant]);
    checkWith($unit, ItemCondition::Working);

    assignTenant($landlord, $tenant, $unit)->call('saveUnitAssignment')->assertHasNoErrors();
    assignTenant($landlord, $roommate, $unit)->call('saveUnitAssignment')->assertHasErrors(['unit_id']);

    checkWith($unit, ItemCondition::Working);

    assignTenant($landlord, $roommate, $unit)->call('saveUnitAssignment')->assertHasNoErrors();

    expect(ConditionCheck::query()->whereNull('lease_id')->exists())->toBeFalse();
});

test('proceeding without a clean move-in check needs a reason, which is kept on the lease', function () {
    [$landlord, $tenant, $unit] = moveInSetup();
    $check = checkWith($unit, ItemCondition::Missing);

    assignTenant($landlord, $tenant, $unit)
        ->set('proceedAnyway', true)
        ->set('overrideReason', 'Soon')
        ->call('saveUnitAssignment')
        ->assertHasErrors(['overrideReason'])
        ->set('overrideReason', 'The tenant agreed to move in while the faucet is replaced.')
        ->call('saveUnitAssignment')
        ->assertHasNoErrors();

    $lease = Lease::query()->sole();

    expect($lease->move_in_override_reason)->toBe('The tenant agreed to move in while the faucet is replaced.')
        ->and($check->fresh()->lease_id)->toBe($lease->id);
});

test('staff cannot proceed without a clean move-in check', function () {
    [$landlord, $tenant, $unit] = moveInSetup();
    $staff = User::factory()->create();
    $landlord->currentTeam->members()->attach($staff, ['role' => TeamRole::Member]);
    $staff->switchTeam($landlord->currentTeam);

    assignTenant($staff, $tenant, $unit)
        ->assertSee('Only the landlord or a manager can assign a tenant without a clean move-in check.')
        ->set('proceedAnyway', true)
        ->set('overrideReason', 'I would like to skip the check this time.')
        ->call('saveUnitAssignment')
        ->assertForbidden();

    expect(Lease::query()->exists())->toBeFalse();
});

test('picking another unit clears a reason given for the previous one', function () {
    [$landlord, $tenant, $unit] = moveInSetup();
    $otherUnit = Unit::factory()->for($unit->property)->create(['status' => UnitStatus::Vacant]);

    assignTenant($landlord, $tenant, $unit)
        ->set('proceedAnyway', true)
        ->set('overrideReason', 'Agreed with the tenant on the phone.')
        ->set('unit_id', $otherUnit->id)
        ->assertSet('proceedAnyway', false)
        ->assertSet('overrideReason', '');
});
