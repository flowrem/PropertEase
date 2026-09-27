<?php

use App\Enums\ConditionCheckKind;
use App\Enums\ItemCondition;
use App\Enums\ItemServiceAction;
use App\Enums\TeamRole;
use App\Enums\UnitItemType;
use App\Models\Amenity;
use App\Models\ConditionCheck;
use App\Models\ConditionCheckItem;
use App\Models\ItemService;
use App\Models\Property;
use App\Models\Unit;
use App\Models\UnitItem;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

function inventoryUnit(User $landlord): Unit
{
    return Unit::factory()->for(Property::factory()->for($landlord->currentTeam))->create();
}

function inventoryPage(User $user, Unit $unit): Testable
{
    return Livewire::actingAs($user)->test('pages::landlord.unit-inventory', ['unit' => $unit->id]);
}

function inventoryTeamMember(User $landlord, TeamRole $role): User
{
    $member = User::factory()->create();
    $landlord->currentTeam->members()->attach($member, ['role' => $role]);
    $member->switchTeam($landlord->currentTeam);

    return $member;
}

function platformAmenity(string $name): Amenity
{
    return Amenity::query()->whereNull('team_id')->where('name', $name)->firstOrFail();
}

test('a landlord can open a unit\'s inventory from its address', function () {
    $landlord = User::factory()->create();
    $unit = inventoryUnit($landlord);
    UnitItem::factory()->for($unit)->create(['name' => 'Ceiling light, bedroom']);

    $this->actingAs($landlord)
        ->get(route('units.inventory', ['unit' => $unit]))
        ->assertOk()
        ->assertSee('Ceiling light, bedroom')
        ->assertSee('No move-in check ready');
});

test('tenants cannot open a unit\'s inventory', function () {
    $landlord = User::factory()->create();
    $unit = inventoryUnit($landlord);
    $tenant = inventoryTeamMember($landlord, TeamRole::Tenant);

    $this->actingAs($tenant)->get(route('units.inventory', ['unit' => $unit]))->assertForbidden();
});

test('another landlord\'s unit cannot be opened', function () {
    $landlord = User::factory()->create();
    $otherUnit = inventoryUnit(User::factory()->create());

    expect(fn () => inventoryPage($landlord, $otherUnit))->toThrow(ModelNotFoundException::class);
});

test('a landlord can add, edit and remove items', function () {
    $landlord = User::factory()->create();
    $unit = inventoryUnit($landlord);

    $page = inventoryPage($landlord, $unit)
        ->set(['itemName' => ' Faucet, kitchen ', 'itemType' => UnitItemType::Plumbing->value, 'itemInstalledAt' => '2026-01-15'])
        ->call('saveItem')
        ->assertHasNoErrors();

    $item = $unit->items()->sole();

    expect($item->name)->toBe('Faucet, kitchen')
        ->and($item->item_type)->toBe(UnitItemType::Plumbing)
        ->and($item->installed_at->toDateString())->toBe('2026-01-15');

    $page->call('editItem', $item->id)
        ->set('itemName', 'Faucet, bathroom')
        ->call('saveItem')
        ->assertHasNoErrors();

    expect($item->fresh()->name)->toBe('Faucet, bathroom');

    $page->call('removeItem', $item->id);

    expect(UnitItem::query()->exists())->toBeFalse();
});

test('an item needs a name, a known type and an install date that is not in the future', function (array $input, string $field) {
    $landlord = User::factory()->create();

    inventoryPage($landlord, inventoryUnit($landlord))
        ->set(['itemName' => 'Outlet, living room', 'itemType' => UnitItemType::Electrical->value, ...$input])
        ->call('saveItem')
        ->assertHasErrors([$field]);
})->with([
    'no name' => [['itemName' => ''], 'itemName'],
    'unknown type' => [['itemType' => 'spaceship'], 'itemType'],
    'installed tomorrow' => [['itemInstalledAt' => now()->addDay()->toDateString()], 'itemInstalledAt'],
]);

test('a removed item that was checked leaves the inventory but stays in the check history', function () {
    $landlord = User::factory()->create();
    $unit = inventoryUnit($landlord);
    $item = UnitItem::factory()->for($unit)->create(['name' => 'Old electric fan']);
    ConditionCheckItem::factory()
        ->for(ConditionCheck::factory()->for($unit)->kind(ConditionCheckKind::Routine), 'check')
        ->for($item, 'unitItem')
        ->create();

    inventoryPage($landlord, $unit)->call('removeItem', $item->id);

    expect($item->fresh()->removed_at)->not->toBeNull()
        ->and($unit->items()->active()->count())->toBe(0);

    inventoryPage($landlord, $unit)->assertSee('Old electric fan')->assertSee('No items listed yet');
});

test('adding from amenities lists each piece once, however often it is run', function () {
    $landlord = User::factory()->create();
    $unit = inventoryUnit($landlord);
    $doubleDeck = platformAmenity('Double deck');
    $unit->amenities()->attach([$doubleDeck->id => ['quantity' => 2], platformAmenity('Refrigerator')->id => ['quantity' => 1]]);

    inventoryPage($landlord, $unit)
        ->call('addFromAmenities')
        ->call('addFromAmenities');

    $items = $unit->items()->orderBy('name')->get();

    expect($items->pluck('name')->all())->toBe(['Double deck 1', 'Double deck 2', 'Refrigerator'])
        ->and($items->firstWhere('name', 'Refrigerator')->item_type)->toBe(UnitItemType::Appliance)
        ->and($items->firstWhere('name', 'Double deck 1')->amenity_id)->toBe($doubleDeck->id);
});

test('copying from another unit skips names already listed and only keeps amenity links this unit has', function () {
    $landlord = User::factory()->create();
    $unit = inventoryUnit($landlord);
    $source = inventoryUnit($landlord);
    $fan = platformAmenity('Electric fan');
    $source->amenities()->attach($fan->id, ['quantity' => 1]);
    UnitItem::factory()->for($source)->create(['name' => 'Electric fan', 'amenity_id' => $fan->id]);
    UnitItem::factory()->for($source)->create(['name' => 'Ceiling light']);
    UnitItem::factory()->for($source)->removed()->create(['name' => 'Broken heater']);
    UnitItem::factory()->for($unit)->create(['name' => 'ceiling light']);

    inventoryPage($landlord, $unit)
        ->set('copyFromUnitId', $source->id)
        ->call('copyFromUnit')
        ->assertHasNoErrors();

    expect($unit->items()->count())->toBe(2)
        ->and($unit->items()->where('name', 'Electric fan')->sole()->amenity_id)->toBeNull();
});

test('another landlord\'s unit cannot be copied from', function () {
    $landlord = User::factory()->create();
    $otherUnit = inventoryUnit(User::factory()->create());
    UnitItem::factory()->for($otherUnit)->create();
    $unit = inventoryUnit($landlord);

    inventoryPage($landlord, $unit)
        ->set('copyFromUnitId', $otherUnit->id)
        ->call('copyFromUnit')
        ->assertHasErrors(['copyFromUnitId']);

    expect($unit->items()->count())->toBe(0);
});

test('staff can record a check but cannot change the item list', function () {
    $landlord = User::factory()->create();
    $unit = inventoryUnit($landlord);
    $item = UnitItem::factory()->for($unit)->create();
    $staff = inventoryTeamMember($landlord, TeamRole::Member);

    inventoryPage($staff, $unit)
        ->call('startCheck')
        ->set("conditions.{$item->id}", ItemCondition::Working->value)
        ->call('saveCheck')
        ->assertHasNoErrors();

    expect($unit->conditionChecks()->sole()->checked_by)->toBe($staff->id);

    inventoryPage($staff, $unit)->assertSee('Ask your landlord or a manager to change the item list');
    inventoryPage($staff, $unit)->set(['itemName' => 'Sink', 'itemType' => 'plumbing'])->call('saveItem')->assertForbidden();
    inventoryPage($staff, $unit)->call('removeItem', $item->id)->assertForbidden();
    inventoryPage($staff, $unit)->call('addFromAmenities')->assertForbidden();

    expect($unit->items()->count())->toBe(1);
});

test('a check needs a condition picked for every item still in the unit', function () {
    $landlord = User::factory()->create();
    $unit = inventoryUnit($landlord);
    $light = UnitItem::factory()->for($unit)->create();
    $faucet = UnitItem::factory()->for($unit)->create();
    UnitItem::factory()->for($unit)->removed()->create();

    inventoryPage($landlord, $unit)
        ->call('startCheck')
        ->set("conditions.{$light->id}", ItemCondition::Working->value)
        ->call('saveCheck')
        ->assertHasErrors(["conditions.{$faucet->id}"])
        ->set("conditions.{$faucet->id}", ItemCondition::Missing->value)
        ->set("remarks.{$faucet->id}", 'Handle gone')
        ->call('saveCheck')
        ->assertHasNoErrors();

    $check = $unit->conditionChecks()->with('items')->sole();

    expect($check->kind)->toBe(ConditionCheckKind::MoveIn)
        ->and($check->lease_id)->toBeNull()
        ->and($check->items)->toHaveCount(2)
        ->and($check->blockingItemCount())->toBe(1)
        ->and($check->items->firstWhere('unit_item_id', $faucet->id)->remarks)->toBe('Handle gone');
});

test('a check cannot be recorded before any item is listed', function () {
    $landlord = User::factory()->create();

    inventoryPage($landlord, inventoryUnit($landlord))
        ->call('startCheck')
        ->call('saveCheck')
        ->assertHasErrors(['conditions']);

    expect(ConditionCheck::query()->exists())->toBeFalse();
});

test('a new check can start from what the last check found', function () {
    $landlord = User::factory()->create();
    $unit = inventoryUnit($landlord);
    $item = UnitItem::factory()->for($unit)->create();
    ConditionCheckItem::factory()
        ->for(ConditionCheck::factory()->for($unit), 'check')
        ->for($item, 'unitItem')
        ->condition(ItemCondition::NeedsRepair)
        ->create(['remarks' => 'Flickers']);

    inventoryPage($landlord, $unit)
        ->call('startCheck')
        ->call('fillFromLastCheck')
        ->assertSet("conditions.{$item->id}", ItemCondition::NeedsRepair->value)
        ->assertSet("remarks.{$item->id}", 'Flickers');
});

test('an item fixed 3 or more times in the last 6 months is flagged as having repeated problems', function (array $fixesMonthsAgo, bool $flagged) {
    $landlord = User::factory()->create();
    $unit = inventoryUnit($landlord);
    $item = UnitItem::factory()->for($unit)->create(['name' => 'Aircon, bedroom']);

    foreach ($fixesMonthsAgo as $monthsAgo) {
        ItemService::factory()->for($item)->create(['performed_at' => today()->subMonths($monthsAgo)->addDay()]);
    }
    ItemService::factory()->for($item)->action(ItemServiceAction::Inspected)->create();

    $page = inventoryPage($landlord, $unit);

    $flagged ? $page->assertSee('Repeated problems') : $page->assertDontSee('Repeated problems');
})->with([
    'three recent fixes' => [[0, 2, 6], true],
    'two recent fixes and an older one' => [[0, 2, 7], false],
    'two recent fixes (the inspection does not count)' => [[0, 1], false],
]);

test('an item shows how often it was repaired and replaced', function () {
    $landlord = User::factory()->create();
    $unit = inventoryUnit($landlord);
    $item = UnitItem::factory()->for($unit)->create();
    ItemService::factory()->for($item)->action(ItemServiceAction::Replaced)->create(['performed_at' => '2026-03-01']);
    ItemService::factory()->for($item)->action(ItemServiceAction::Replaced)->create(['performed_at' => '2026-09-12']);
    ItemService::factory()->for($item)->action(ItemServiceAction::Repaired)->create(['performed_at' => '2026-05-01', 'cost' => 350]);

    inventoryPage($landlord, $unit)
        ->assertSee('Replaced 2 times and repaired once, last on Sep 12, 2026')
        ->assertSeeHtml('&#8369;350.00');
});

test('a landlord can log work on an item without a tenant report, and staff cannot', function () {
    $landlord = User::factory()->create();
    $unit = inventoryUnit($landlord);
    $item = UnitItem::factory()->for($unit)->create();

    inventoryPage($landlord, $unit)
        ->call('startLoggingService', $item->id)
        ->set('serviceAction', ItemServiceAction::Inspected->value)
        ->set('servicePerformedAt', now()->addDay()->toDateString())
        ->call('saveService')
        ->assertHasErrors(['servicePerformedAt'])
        ->set('servicePerformedAt', today()->toDateString())
        ->set('serviceNotes', 'Cleaned the filter')
        ->call('saveService')
        ->assertHasNoErrors();

    expect($item->services()->sole())
        ->action->toBe(ItemServiceAction::Inspected)
        ->notes->toBe('Cleaned the filter')
        ->concern_id->toBeNull();

    $staff = inventoryTeamMember($landlord, TeamRole::Member);

    inventoryPage($staff, $unit)->call('startLoggingService', $item->id)->assertForbidden();
});
