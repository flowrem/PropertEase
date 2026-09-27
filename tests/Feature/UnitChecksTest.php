<?php

use App\Enums\ConditionCheckKind;
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
use App\Notifications\RoutineCheckRecorded;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
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
        ->get(route('unit-checks'))
        ->assertOk()
        ->assertSee('Faucet, kitchen')
        ->assertSee('Needs repair')
        ->assertSee('Drips slowly')
        ->assertSee('I acknowledge this checklist');
});

test('a tenant can acknowledge their checklist once', function () {
    [$tenant, $check] = tenantWithMoveInCheck(User::factory()->create());

    $this->travelTo(now()->subDay());
    Livewire::actingAs($tenant)->test('pages::unit-checks')->call('acknowledge');
    $this->travelBack();

    $acknowledgedAt = $check->fresh()->tenant_acknowledged_at;

    Livewire::actingAs($tenant)->test('pages::unit-checks')
        ->assertSee('You acknowledged this checklist')
        ->call('acknowledge');

    expect($acknowledgedAt)->not->toBeNull()
        ->and($check->fresh()->tenant_acknowledged_at->equalTo($acknowledgedAt))->toBeTrue();
});

test('the dashboard asks a tenant to acknowledge their checklist until they do', function () {
    [$tenant, $check] = tenantWithMoveInCheck(User::factory()->create());

    $this->actingAs($tenant)->get(route('dashboard'))
        ->assertSee('Unit checks')
        ->assertSee('Please acknowledge');

    $check->acknowledge();

    $this->actingAs($tenant)->get(route('dashboard'))
        ->assertSee('Unit checks')
        ->assertDontSee('Please acknowledge');
});

test('a roommate sees their own checklist, not the other tenant\'s', function () {
    $landlord = User::factory()->create();
    [$tenant, $tenantsCheck] = tenantWithMoveInCheck($landlord);
    [$roommate, $roommatesCheck] = tenantWithMoveInCheck($landlord, $tenantsCheck->unit);

    Livewire::actingAs($roommate)->test('pages::unit-checks')->call('acknowledge');

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

    Livewire::actingAs($tenant)->test('pages::unit-checks')
        ->assertSee('You are not renting a unit here right now.');

    Lease::factory()->for(Unit::factory()->for(Property::factory()->for($landlord->currentTeam)))
        ->create(['tenant_id' => $tenant->id, 'status' => LeaseStatus::Active]);

    Livewire::actingAs($tenant)->test('pages::unit-checks')
        ->assertSee('Your landlord has not recorded a move-in checklist for your unit.');
});

test('recording a routine check tells the tenants living in that unit, and only them', function () {
    Notification::fake();
    $landlord = User::factory()->create();
    [$tenant, $check] = tenantWithMoveInCheck($landlord);
    $unit = $check->unit;
    [$neighbour] = tenantWithMoveInCheck($landlord);
    $formerTenant = User::factory()->create();
    Lease::factory()->for($unit)->create(['tenant_id' => $formerTenant->id, 'status' => LeaseStatus::Ended]);
    $item = $unit->items()->sole();

    $landlord->switchTeam($landlord->currentTeam);

    Livewire::actingAs($landlord)->test('pages::landlord.unit-inventory', ['unit' => $unit->id])
        ->call('startCheck')
        ->set('checkKind', ConditionCheckKind::Routine->value)
        ->set("conditions.{$item->id}", ItemCondition::Working->value)
        ->call('saveCheck')
        ->assertHasNoErrors();

    Notification::assertSentTo($tenant, RoutineCheckRecorded::class);
    Notification::assertNotSentTo([$neighbour, $formerTenant, $landlord], RoutineCheckRecorded::class);
});

test('a move-in check does not send the routine check notice', function () {
    Notification::fake();
    $landlord = User::factory()->create();
    [$tenant, $check] = tenantWithMoveInCheck($landlord);
    $item = $check->unit->items()->sole();
    $landlord->switchTeam($landlord->currentTeam);

    Livewire::actingAs($landlord)->test('pages::landlord.unit-inventory', ['unit' => $check->unit_id])
        ->call('startCheck')
        ->set("conditions.{$item->id}", ItemCondition::Working->value)
        ->call('saveCheck');

    Notification::assertNothingSent();
});

test('a tenant sees the routine checks from their own stay, not from before it', function () {
    $landlord = User::factory()->create();
    [$tenant, $check] = tenantWithMoveInCheck($landlord);
    $check->lease->update(['start_date' => now()->subMonth()]);
    $item = $check->unit->items()->sole();

    foreach (['Before you moved in' => now()->subMonths(2), 'During your stay' => now()->subWeek()] as $notes => $checkedAt) {
        $routine = ConditionCheck::factory()->for($check->unit)->kind(ConditionCheckKind::Routine)->create(['checked_at' => $checkedAt, 'notes' => $notes]);
        ConditionCheckItem::factory()->for($routine, 'check')->for($item, 'unitItem')->create();
    }

    Livewire::actingAs($tenant)->test('pages::unit-checks')
        ->assertSee('Routine checks during your stay')
        ->assertSee('During your stay')
        ->assertDontSee('Before you moved in');
});
