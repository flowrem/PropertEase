<?php

use App\Enums\ConcernCategory;
use App\Enums\ConcernStatus;
use App\Enums\ItemServiceAction;
use App\Enums\LeaseStatus;
use App\Enums\TeamRole;
use App\Models\Concern;
use App\Models\ItemService;
use App\Models\Lease;
use App\Models\Property;
use App\Models\Unit;
use App\Models\UnitItem;
use App\Models\User;
use App\Notifications\ReportUpdated;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * A landlord and an open maintenance report from their tenant, about an
 * inventory item unless told otherwise.
 *
 * @return array{0: User, 1: Concern}
 */
function openReport(bool $aboutItem = true): array
{
    $landlord = User::factory()->create();
    $unit = Unit::factory()->for(Property::factory()->for($landlord->currentTeam))->create();
    $lease = Lease::factory()->for($unit)->create(['status' => LeaseStatus::Active]);
    $concern = Concern::factory()->for($lease)->create([
        'category' => ConcernCategory::Maintenance,
        'status' => ConcernStatus::Pending,
        'unit_item_id' => $aboutItem ? UnitItem::factory()->for($unit)->create(['name' => 'Faucet, kitchen'])->id : null,
    ]);

    return [$landlord, $concern];
}

function reportPanel(User $user, Concern $concern): Testable
{
    return Livewire::actingAs($user)->test('pages::landlord.maintenance')->call('openReportPanel', $concern->id);
}

test('a landlord can reply to a report and the tenant is told', function () {
    Notification::fake();
    [$landlord, $concern] = openReport();

    reportPanel($landlord, $concern)
        ->set('message', 'A plumber is coming tomorrow morning.')
        ->call('saveUpdate')
        ->assertHasNoErrors();

    expect($concern->updates()->sole()->message)->toBe('A plumber is coming tomorrow morning.')
        ->and($concern->fresh()->status)->toBe(ConcernStatus::Pending);

    Notification::assertSentTo($concern->lease->tenant, ReportUpdated::class);
});

test('an update needs a reply or a new status', function () {
    [$landlord, $concern] = openReport();

    reportPanel($landlord, $concern)->call('saveUpdate')->assertHasErrors(['message']);

    reportPanel($landlord, $concern)
        ->set('newStatus', ConcernStatus::InProgress->value)
        ->call('saveUpdate')
        ->assertHasNoErrors();

    expect($concern->fresh()->status)->toBe(ConcernStatus::InProgress)
        ->and($concern->updates()->sole()->message)->toBe('Marked In Progress.');
});

test('resolving a report about an item asks what was done and records it in the item\'s history', function () {
    [$landlord, $concern] = openReport();

    reportPanel($landlord, $concern)
        ->set('newStatus', ConcernStatus::Resolved->value)
        ->assertSee('What was done to the Faucet, kitchen?')
        ->call('saveUpdate')
        ->assertHasErrors(['outcome'])
        ->set('outcome', 'replaced')
        ->set('cost', '850')
        ->set('message', 'Replaced the whole faucet.')
        ->call('saveUpdate')
        ->assertHasNoErrors();

    $service = ItemService::query()->sole();

    expect($concern->fresh())
        ->status->toBe(ConcernStatus::Resolved)
        ->resolved_at->not->toBeNull()
        ->and($service->unit_item_id)->toBe($concern->unit_item_id)
        ->and($service->concern_id)->toBe($concern->id)
        ->and($service->action)->toBe(ItemServiceAction::Replaced)
        ->and($service->cost)->toBe('850.00')
        ->and($service->recorded_by)->toBe($landlord->id);
});

test('resolving with no work needed adds nothing to the item\'s history', function () {
    [$landlord, $concern] = openReport();

    reportPanel($landlord, $concern)
        ->set('newStatus', ConcernStatus::Resolved->value)
        ->set('outcome', 'none')
        ->call('saveUpdate')
        ->assertHasNoErrors();

    expect(ItemService::query()->exists())->toBeFalse();
});

test('a report resolved, reopened and resolved again is recorded in the item\'s history once', function () {
    [$landlord, $concern] = openReport();

    reportPanel($landlord, $concern)->set('newStatus', 'resolved')->set('outcome', 'repaired')->call('saveUpdate');
    reportPanel($landlord, $concern)->set('newStatus', 'pending')->call('saveUpdate');

    expect($concern->fresh()->resolved_at)->toBeNull();

    reportPanel($landlord, $concern)
        ->set('newStatus', 'resolved')
        ->assertDontSee('What was done to')
        ->call('saveUpdate')
        ->assertHasNoErrors();

    expect(ItemService::query()->count())->toBe(1);
});

test('a report not about an item resolves without asking what was done', function () {
    [$landlord, $concern] = openReport(aboutItem: false);

    reportPanel($landlord, $concern)
        ->set('newStatus', ConcernStatus::Resolved->value)
        ->call('saveUpdate')
        ->assertHasNoErrors();

    expect($concern->fresh()->status)->toBe(ConcernStatus::Resolved)
        ->and(ItemService::query()->exists())->toBeFalse();
});

test('staff can read a report but not reply to or resolve it', function () {
    [$landlord, $concern] = openReport();
    $staff = User::factory()->create();
    $landlord->currentTeam->members()->attach($staff, ['role' => TeamRole::Member]);
    $staff->switchTeam($landlord->currentTeam);

    reportPanel($staff, $concern)
        ->assertSee('Only the landlord or a manager can reply to or resolve reports.')
        ->set('message', 'On it.')
        ->call('saveUpdate')
        ->assertForbidden();

    expect($concern->updates()->exists())->toBeFalse();
});

test('another landlord\'s report cannot be opened', function () {
    [, $concern] = openReport();

    expect(fn () => reportPanel(User::factory()->create(), $concern))->toThrow(ModelNotFoundException::class);
});

test('a link with the report in the address opens it', function () {
    [$landlord, $concern] = openReport();

    Livewire::withQueryParams(['report' => $concern->id])
        ->actingAs($landlord)
        ->test('pages::landlord.maintenance')
        ->assertSet('showReportPanel', true)
        ->assertSee($concern->description);
});
