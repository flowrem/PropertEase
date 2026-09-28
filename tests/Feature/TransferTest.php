<?php

use App\Actions\Transfers\CompleteTransfer;
use App\Actions\Transfers\RequestTransfer;
use App\Actions\Transfers\ReviewTransfer;
use App\Enums\BillingTiming;
use App\Enums\ConditionCheckKind;
use App\Enums\LeaseStatus;
use App\Enums\StayType;
use App\Enums\TeamRole;
use App\Enums\TransferStatus;
use App\Enums\UnitStatus;
use App\Models\ConditionCheck;
use App\Models\Lease;
use App\Models\Property;
use App\Models\TransferRequest;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\ContractReady;
use App\Notifications\TransferRequested;
use App\Notifications\TransferUpdated;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * A landlord with a tenant living in Unit 101 and a vacant Unit 202 ready
 * for move-in, on the same property.
 *
 * @return array{landlord: User, tenant: User, lease: Lease, from: Unit, to: Unit}
 */
function transferSetup(): array
{
    $landlord = User::factory()->create();
    $tenant = User::factory()->create(['name' => 'Juana Dela Cruz']);
    $landlord->currentTeam->members()->attach($tenant, ['role' => TeamRole::Tenant]);

    $property = Property::factory()->for($landlord->currentTeam)->create();
    $from = Unit::factory()->for($property)->create(['unit_number' => '101', 'status' => UnitStatus::Occupied, 'price' => 4500]);
    $to = Unit::factory()->for($property)->readyForMoveIn()->create(['unit_number' => '202', 'status' => UnitStatus::Vacant, 'price' => 5200]);

    $lease = Lease::factory()->for($from)->for($tenant, 'tenant')->create([
        'status' => LeaseStatus::Active,
        'due_day' => 7,
        'billing_timing' => BillingTiming::Arrears,
        'stay_type' => StayType::LongTerm,
    ]);
    $from->splitRentAmongActiveTenants();

    return compact('landlord', 'tenant', 'lease', 'from', 'to');
}

function recordMoveOutCheck(Unit $unit): ConditionCheck
{
    return ConditionCheck::factory()->for($unit)->create(['kind' => ConditionCheckKind::MoveOut, 'checked_at' => now()]);
}

test('a tenant asks to move and the landlord\'s side is told', function () {
    Notification::fake();
    ['landlord' => $landlord, 'lease' => $lease, 'to' => $to] = transferSetup();
    $manager = User::factory()->create();
    $staff = User::factory()->create();
    $landlord->currentTeam->members()->attach($manager, ['role' => TeamRole::Admin]);
    $landlord->currentTeam->members()->attach($staff, ['role' => TeamRole::Member]);

    $transfer = app(RequestTransfer::class)->handle($lease, $to, '  Closer to the stairs.  ', now()->addWeek());

    expect($transfer->status)->toBe(TransferStatus::Pending)
        ->and($transfer->reason)->toBe('Closer to the stairs.')
        ->and($transfer->from_unit_id)->toBe($lease->unit_id)
        ->and($transfer->team_id)->toBe($landlord->currentTeam->id)
        ->and($to->fresh()->hasRoomForAnotherTenant())->toBeTrue();

    Notification::assertSentTo([$landlord, $manager, $staff], TransferRequested::class);
    Notification::assertNotSentTo($lease->tenant, TransferRequested::class);
});

test('a transfer is refused to another landlord\'s unit, the same unit, a full unit, twice, or from an ended lease', function (Closure $scenario) {
    Notification::fake();
    $setup = transferSetup();

    [$lease, $unit] = $scenario($setup);

    expect(fn () => app(RequestTransfer::class)->handle($lease, $unit, 'Please.', now()->addWeek()))
        ->toThrow(ValidationException::class);
})->with([
    'another landlord' => [fn (array $setup) => [$setup['lease'], Unit::factory()->create(['status' => UnitStatus::Vacant])]],
    'the same unit' => [fn (array $setup) => [$setup['lease'], $setup['from']]],
    'a full unit' => [function (array $setup) {
        Lease::factory()->for($setup['to'])->create(['status' => LeaseStatus::Active]);
        $setup['to']->update(['status' => UnitStatus::Occupied]);

        return [$setup['lease'], $setup['to']];
    }],
    'a second open request' => [function (array $setup) {
        TransferRequest::factory()->create(['lease_id' => $setup['lease']->id, 'to_unit_id' => $setup['to']->id]);

        return [$setup['lease'], $setup['to']];
    }],
    'an ended lease' => [function (array $setup) {
        $setup['lease']->forceFill(['status' => LeaseStatus::Ended])->save();

        return [$setup['lease'], $setup['to']];
    }],
]);

test('approving sets the move date, holds the new unit\'s slot and tells the tenant', function () {
    Notification::fake();
    ['landlord' => $landlord, 'tenant' => $tenant, 'lease' => $lease, 'to' => $to] = transferSetup();
    $transfer = app(RequestTransfer::class)->handle($lease, $to, 'Closer to the stairs.', now()->addWeek());

    app(ReviewTransfer::class)->approve($transfer, $landlord, now()->addDays(3));

    expect($transfer->fresh()->status)->toBe(TransferStatus::Approved)
        ->and($transfer->fresh()->move_date->toDateString())->toBe(now()->addDays(3)->toDateString())
        ->and($transfer->fresh()->reviewed_by)->toBe($landlord->id)
        ->and($to->fresh()->hasRoomForAnotherTenant())->toBeFalse();

    Notification::assertSentTo($tenant, TransferUpdated::class, fn (TransferUpdated $notification) => str_contains($notification->toArray($tenant)['message'], 'approved'));
});

test('approving is refused for a past date, twice, or when the unit filled up meanwhile', function () {
    Notification::fake();
    ['landlord' => $landlord, 'lease' => $lease, 'to' => $to] = transferSetup();
    $transfer = app(RequestTransfer::class)->handle($lease, $to, 'Please.', now()->addWeek());

    expect(fn () => app(ReviewTransfer::class)->approve($transfer, $landlord, now()->subDay()))->toThrow(ValidationException::class);

    Lease::factory()->for($to)->create(['status' => LeaseStatus::Active]);
    $to->update(['status' => UnitStatus::Occupied]);

    expect(fn () => app(ReviewTransfer::class)->approve($transfer->fresh(), $landlord, now()->addDay()))->toThrow(ValidationException::class);
    expect($transfer->fresh()->status)->toBe(TransferStatus::Pending);
});

test('rejecting and cancelling need a reason, tell the tenant and release the held slot', function () {
    Notification::fake();
    ['landlord' => $landlord, 'tenant' => $tenant, 'lease' => $lease, 'to' => $to] = transferSetup();
    $rejected = app(RequestTransfer::class)->handle($lease, $to, 'Please.', now()->addWeek());

    expect(fn () => app(ReviewTransfer::class)->reject($rejected, $landlord, ' '))->toThrow(ValidationException::class);

    app(ReviewTransfer::class)->reject($rejected, $landlord, 'Unit 202 is being repainted.');

    expect($rejected->fresh()->status)->toBe(TransferStatus::Rejected)
        ->and($rejected->fresh()->decision_reason)->toBe('Unit 202 is being repainted.');

    $approved = app(RequestTransfer::class)->handle($lease, $to, 'Please again.', now()->addWeek());
    app(ReviewTransfer::class)->approve($approved, $landlord, now()->addDay());

    expect(fn () => app(ReviewTransfer::class)->reject($approved->fresh(), $landlord, 'Late no.'))->toThrow(ValidationException::class);

    app(ReviewTransfer::class)->cancel($approved->fresh(), $landlord, 'Plumbing work found.');

    expect($approved->fresh()->status)->toBe(TransferStatus::Cancelled)
        ->and($to->fresh()->hasRoomForAnotherTenant())->toBeTrue();

    Notification::assertSentToTimes($tenant, TransferUpdated::class, 3);
});

test('completing the move is refused before the move date or without a move-out check', function () {
    Notification::fake();
    ['landlord' => $landlord, 'lease' => $lease, 'from' => $from, 'to' => $to] = transferSetup();
    $transfer = app(RequestTransfer::class)->handle($lease, $to, 'Please.', now()->addWeek());
    app(ReviewTransfer::class)->approve($transfer, $landlord, now()->addDays(2));

    recordMoveOutCheck($from);

    expect(fn () => app(CompleteTransfer::class)->handle($transfer->fresh(), $landlord))->toThrow(ValidationException::class);

    $this->travel(2)->days();
    ConditionCheck::query()->where('kind', ConditionCheckKind::MoveOut->value)->delete();

    expect(fn () => app(CompleteTransfer::class)->handle($transfer->fresh(), $landlord))->toThrow(ValidationException::class);
    expect($lease->fresh()->status)->toBe(LeaseStatus::Active);
});

test('completing the move ends the old lease, starts the new one on the same terms, re-splits rent and makes the new contract', function () {
    Notification::fake();
    ['landlord' => $landlord, 'tenant' => $tenant, 'lease' => $lease, 'from' => $from, 'to' => $to] = transferSetup();
    $transfer = app(RequestTransfer::class)->handle($lease, $to, 'Please.', now());
    app(ReviewTransfer::class)->approve($transfer, $landlord, now());
    $moveOut = recordMoveOutCheck($from);

    $newLease = app(CompleteTransfer::class)->handle($transfer->fresh(), $landlord);

    expect($lease->fresh()->status)->toBe(LeaseStatus::Ended)
        ->and($from->fresh()->status)->toBe(UnitStatus::Vacant)
        ->and($moveOut->fresh()->lease_id)->toBe($lease->id)
        ->and($newLease->unit_id)->toBe($to->id)
        ->and($newLease->due_day)->toBe(7)
        ->and($newLease->billing_timing)->toBe(BillingTiming::Arrears)
        ->and($newLease->stay_type)->toBe(StayType::LongTerm)
        ->and($newLease->currentRent->amount)->toEqual('5200.00')
        ->and($newLease->contract)->not->toBeNull()
        ->and($to->fresh()->status)->toBe(UnitStatus::Occupied)
        ->and($to->fresh()->incomingTransferCount())->toBe(0)
        ->and($transfer->fresh()->status)->toBe(TransferStatus::Completed)
        ->and($transfer->fresh()->new_lease_id)->toBe($newLease->id);

    Notification::assertSentTo($tenant, ContractReady::class);
    Notification::assertSentTo($tenant, TransferUpdated::class, fn (TransferUpdated $notification) => $notification->toArray($tenant)['route'] === 'contract');
});

test('completing the move needs the new unit\'s move-in check or the landlord\'s reason', function () {
    Notification::fake();
    ['landlord' => $landlord, 'lease' => $lease, 'from' => $from] = transferSetup();
    $to = Unit::factory()->for($from->property)->create(['status' => UnitStatus::Vacant, 'price' => 5000]);
    $transfer = app(RequestTransfer::class)->handle($lease, $to, 'Please.', now());
    app(ReviewTransfer::class)->approve($transfer, $landlord, now());
    recordMoveOutCheck($from);

    expect(fn () => app(CompleteTransfer::class)->handle($transfer->fresh(), $landlord))->toThrow(ValidationException::class);
    expect($lease->fresh()->status)->toBe(LeaseStatus::Active);

    $newLease = app(CompleteTransfer::class)->handle($transfer->fresh(), $landlord, 'Tenant agreed to move before the check.');

    expect($newLease->move_in_override_reason)->toBe('Tenant agreed to move before the check.');
});

test('a tenant sees their rent change, sends a request from the Transfer page and can withdraw it', function () {
    Notification::fake();
    ['landlord' => $landlord, 'tenant' => $tenant, 'to' => $to] = transferSetup();
    $tenant->switchTeam($landlord->currentTeam);

    $this->actingAs($tenant)->get(route('transfer'))->assertOk()->assertSee('Unit 202');

    Livewire::actingAs($tenant)
        ->test('pages::transfer')
        ->set('to_unit_id', $to->id)
        ->assertSee('Your rent would change from ₱4,500.00 to ₱5,200.00 per month.')
        ->set('preferred_date', now()->addWeek()->toDateString())
        ->set('reason', 'I need the ground floor.')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSee('Waiting for your landlord.')
        ->call('withdraw')
        ->assertSee('Earlier requests');

    expect(TransferRequest::firstOrFail()->status)->toBe(TransferStatus::Cancelled);
});

test('the Transfer page validates the request and only offers the landlord\'s units with room', function (array $input, string $field) {
    Notification::fake();
    ['landlord' => $landlord, 'tenant' => $tenant, 'to' => $to] = transferSetup();
    $foreignUnit = Unit::factory()->create(['status' => UnitStatus::Vacant]);
    $tenant->switchTeam($landlord->currentTeam);

    $component = Livewire::actingAs($tenant)
        ->test('pages::transfer')
        ->assertDontSee('Unit '.$foreignUnit->unit_number.' ');

    foreach ([
        'to_unit_id' => $to->id,
        'preferred_date' => now()->addWeek()->toDateString(),
        'reason' => 'I need the ground floor.',
        ...array_map(fn ($value) => $value instanceof Closure ? $value($foreignUnit) : $value, $input),
    ] as $key => $value) {
        $component->set($key, $value);
    }

    $component->call('submit')->assertHasErrors([$field]);

    expect(TransferRequest::count())->toBe(0);
})->with([
    'no unit' => [['to_unit_id' => null], 'to_unit_id'],
    'another landlord\'s unit' => [['to_unit_id' => fn (Unit $foreignUnit) => $foreignUnit->id], 'to_unit_id'],
    'a date in the past' => [['preferred_date' => '2020-01-01'], 'preferred_date'],
    'more than 90 days ahead' => [['preferred_date' => '2099-01-01'], 'preferred_date'],
    'a short reason' => [['reason' => 'Move me'], 'reason'],
]);

test('another tenant cannot withdraw someone else\'s request', function () {
    Notification::fake();
    ['landlord' => $landlord, 'lease' => $lease, 'to' => $to] = transferSetup();
    $transfer = app(RequestTransfer::class)->handle($lease, $to, 'Please move me.', now()->addWeek());
    $other = User::factory()->create();
    $landlord->currentTeam->members()->attach($other, ['role' => TeamRole::Tenant]);

    expect(Gate::forUser($other)->allows('withdraw', $transfer))->toBeFalse()
        ->and(Gate::forUser($lease->tenant)->allows('withdraw', $transfer))->toBeTrue();
});
