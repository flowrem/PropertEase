<?php

use App\Actions\Contracts\GenerateLeaseContract;
use App\Enums\ItemCondition;
use App\Enums\LeaseStatus;
use App\Enums\TeamRole;
use App\Enums\UnitStatus;
use App\Models\Amenity;
use App\Models\ConditionCheckItem;
use App\Models\ContractTemplate;
use App\Models\Lease;
use App\Models\LeaseContract;
use App\Models\Property;
use App\Models\Unit;
use App\Models\UnitItem;
use App\Models\User;
use App\Notifications\ContractReady;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * A landlord with a tenant on their team and a unit ready for move-in.
 *
 * @return array{0: User, 1: User, 2: Unit}
 */
function contractSetup(): array
{
    $landlord = User::factory()->create(['contact_number' => '+639170000001']);
    $tenant = User::factory()->create(['name' => 'Juana Dela Cruz', 'contact_number' => '+639171234567']);
    $landlord->currentTeam->members()->attach($tenant, ['role' => TeamRole::Tenant]);

    $unit = Unit::factory()
        ->for(Property::factory()->for($landlord->currentTeam)->create(['name' => 'Sunrise Apartments']))
        ->readyForMoveIn()
        ->create(['unit_number' => '101', 'status' => UnitStatus::Vacant, 'price' => 5000, 'floor_area_sqm' => 24]);

    return [$landlord, $tenant, $unit];
}

function moveTenantIn(User $landlord, User $tenant, Unit $unit, string $stayType = 'long_term'): Lease
{
    Livewire::actingAs($landlord)
        ->test('pages::landlord.tenants')
        ->call('manageTenant', $tenant->id)
        ->set('unit_id', $unit->id)
        ->set('due_day', 5)
        ->set('stay_type', $stayType)
        ->call('saveUnitAssignment')
        ->assertHasNoErrors();

    return Lease::query()->where('unit_id', $unit->id)->where('tenant_id', $tenant->id)->where('status', LeaseStatus::Active->value)->firstOrFail();
}

test('moving a tenant in makes their contract from the landlord\'s terms and tells them', function () {
    Notification::fake();
    [$landlord, $tenant, $unit] = contractSetup();
    ContractTemplate::factory()->for($landlord->currentTeam)->create([
        'advance_months' => 1,
        'deposit_months' => 2,
        'minimum_stay_months_long' => 6,
        'notice_days' => 15,
        'late_fee' => 200,
        'house_rules' => 'Quiet hours from 10 PM.',
    ]);
    $unit->amenities()->attach(Amenity::query()->whereNull('team_id')->where('name', 'Double deck')->value('id'), ['quantity' => 2]);

    $lease = moveTenantIn($landlord, $tenant, $unit);
    $contract = $lease->contract;

    expect($contract)->not->toBeNull()
        ->and($contract->isAccepted())->toBeFalse()
        ->and($contract->generated_by)->toBe($landlord->id)
        ->and($contract->terms['rent'])->toEqual(5000)
        ->and($contract->terms['advance'])->toEqual(['months' => 1, 'amount' => 5000])
        ->and($contract->terms['deposit'])->toEqual(['months' => 2, 'amount' => 10000])
        ->and($contract->terms['stay'])->toBe(['type' => 'Long-term', 'minimum_months' => 6])
        ->and($contract->terms['tenant']['contact_number'])->toBe('0917 123 4567')
        ->and($contract->terms['amenities'])->toBe(['2 × Double deck'])
        ->and($contract->terms['move_in_check']['issues'])->toBe([]);

    expect($contract->body_html)
        ->toContain('Juana Dela Cruz')
        ->toContain('Unit 101 of Sunrise Apartments')
        ->toContain('₱5,000.00')
        ->toContain('₱10,000.00')
        ->toContain('minimum of 6 months')
        ->toContain('15 days of notice')
        ->toContain('₱200.00')
        ->toContain('Quiet hours from 10 PM.');

    Notification::assertSentTo($tenant, ContractReady::class);
});

test('a contract made without landlord terms uses the defaults', function () {
    Notification::fake();
    [$landlord, $tenant, $unit] = contractSetup();

    $contract = moveTenantIn($landlord, $tenant, $unit, 'short_term')->contract;

    expect($contract->terms['advance']['months'])->toBe(1)
        ->and($contract->terms['deposit']['months'])->toBe(2)
        ->and($contract->terms['stay'])->toBe(['type' => 'Short-term', 'minimum_months' => 1])
        ->and($contract->terms['late_fee'])->toBeNull()
        ->and($contract->body_html)->toContain('No late payment fee.');
});

test('the contract lists move-in items that were not in working order', function () {
    Notification::fake();
    [$landlord, $tenant, $unit] = contractSetup();
    $check = $unit->conditionChecks()->latest('id')->firstOrFail();
    $faucet = UnitItem::factory()->for($unit)->create(['name' => 'Kitchen faucet']);
    ConditionCheckItem::factory()->for($check, 'check')->for($faucet, 'unitItem')->create(['condition' => ItemCondition::NeedsRepair, 'remarks' => 'drips']);

    $contract = moveTenantIn($landlord, $tenant, $unit)->contract;

    expect($contract->terms['move_in_check']['issues'])->toBe(['Kitchen faucet: Needs repair (drips)'])
        ->and($contract->body_html)->toContain('Kitchen faucet: Needs repair (drips)');
});

test('a contract keeps what it said after the terms, rent and unit change', function () {
    Notification::fake();
    [$landlord, $tenant, $unit] = contractSetup();
    $lease = moveTenantIn($landlord, $tenant, $unit);
    $contract = $lease->contract;
    $body = $contract->body_html;
    $terms = $contract->terms;

    $landlord->currentTeam->contractTemplate()->updateOrCreate([], ['advance_months' => 3, 'house_rules' => 'No pets.']);
    $unit->update(['price' => 9000, 'unit_number' => '101-B']);

    expect($contract->fresh()->body_html)->toBe($body)
        ->and($contract->fresh()->terms)->toBe($terms);
});

test('an unaccepted contract can be made again from the current terms, an accepted one cannot', function () {
    Notification::fake();
    [$landlord, $tenant, $unit] = contractSetup();
    $lease = moveTenantIn($landlord, $tenant, $unit);
    $contractId = $lease->contract->id;

    $landlord->currentTeam->contractTemplate()->updateOrCreate([], ['house_rules' => 'No pets.']);
    $remade = app(GenerateLeaseContract::class)->handle($lease->fresh(), $landlord);

    expect($remade->id)->toBe($contractId)
        ->and($remade->body_html)->toContain('No pets.')
        ->and(LeaseContract::count())->toBe(1);

    $remade->accept('203.0.113.9');

    expect(fn () => app(GenerateLeaseContract::class)->handle($lease->fresh(), $landlord))
        ->toThrow(ValidationException::class);

    expect($remade->fresh()->body_html)->toContain('No pets.');
});

test('moving to another unit gives the new lease its own contract and leaves the old one as it was', function () {
    Notification::fake();
    [$landlord, $tenant, $unit] = contractSetup();
    $firstLease = moveTenantIn($landlord, $tenant, $unit);
    $firstBody = $firstLease->contract->body_html;

    $otherUnit = Unit::factory()->for($unit->property)->readyForMoveIn()->create(['unit_number' => '202', 'status' => UnitStatus::Vacant, 'price' => 6000]);
    $secondLease = moveTenantIn($landlord, $tenant, $otherUnit);

    expect($secondLease->id)->not->toBe($firstLease->id)
        ->and($secondLease->contract->body_html)->toContain('Unit 202')
        ->and($firstLease->fresh()->contract->body_html)->toBe($firstBody)
        ->and(LeaseContract::count())->toBe(2);
});

test('text the landlord writes is escaped in the contract', function () {
    Notification::fake();
    [$landlord, $tenant, $unit] = contractSetup();
    ContractTemplate::factory()->for($landlord->currentTeam)->create(['house_rules' => '<script>alert(1)</script>']);

    $contract = moveTenantIn($landlord, $tenant, $unit)->contract;

    expect($contract->body_html)->not->toContain('<script>')
        ->toContain('&lt;script&gt;');
});

test('a tenant reads their contract and agrees to it once, recording when and from where', function () {
    Notification::fake();
    [$landlord, $tenant, $unit] = contractSetup();
    $contract = moveTenantIn($landlord, $tenant, $unit)->contract;
    $tenant->switchTeam($landlord->currentTeam);

    $this->actingAs($tenant)->get(route('dashboard'))->assertSee('Please read and agree');

    $component = Livewire::actingAs($tenant)
        ->test('pages::contract')
        ->assertSee('Unit 101 of Sunrise Apartments')
        ->assertSee('I agree')
        ->call('accept')
        ->assertHasErrors(['hasRead'])
        ->set('hasRead', true)
        ->call('accept')
        ->assertHasNoErrors()
        ->assertSee('You agreed to this contract on')
        ->assertDontSee('I have read this contract and agree to its terms.');

    $contract->refresh();
    expect($contract->tenant_accepted_at)->not->toBeNull()
        ->and($contract->tenant_accepted_ip)->toBe('127.0.0.1');

    $component->set('hasRead', true)->call('accept')->assertForbidden();
});

test('only the lease\'s own tenant can agree to its contract', function () {
    Notification::fake();
    [$landlord, $tenant, $unit] = contractSetup();
    $contract = moveTenantIn($landlord, $tenant, $unit)->contract;
    $roommate = User::factory()->create();
    $landlord->currentTeam->members()->attach($roommate, ['role' => TeamRole::Tenant]);

    expect(Gate::forUser($tenant)->allows('accept', $contract))->toBeTrue()
        ->and(Gate::forUser($landlord)->allows('accept', $contract))->toBeFalse()
        ->and(Gate::forUser($roommate)->allows('accept', $contract))->toBeFalse();

    $roommate->switchTeam($landlord->currentTeam);
    Livewire::actingAs($roommate)->test('pages::contract')->assertSee('You don\'t have a unit with this landlord yet.');
});

test('a tenant without a contract yet is told it is coming', function () {
    [$landlord, $tenant, $unit] = contractSetup();
    Lease::factory()->for($unit)->for($tenant, 'tenant')->create(['status' => LeaseStatus::Active]);
    $tenant->switchTeam($landlord->currentTeam);

    Livewire::actingAs($tenant)->test('pages::contract')->assertSee('Your landlord hasn\'t made your contract yet.');
});

test('the print page opens for the tenant and the landlord\'s side only', function () {
    Notification::fake();
    [$landlord, $tenant, $unit] = contractSetup();
    $contract = moveTenantIn($landlord, $tenant, $unit)->contract;
    $staff = User::factory()->create();
    $landlord->currentTeam->members()->attach($staff, ['role' => TeamRole::Member]);
    $roommate = User::factory()->create();
    $landlord->currentTeam->members()->attach($roommate, ['role' => TeamRole::Tenant]);

    $landlord->switchTeam($landlord->currentTeam);
    $url = route('contracts.print', ['contract' => $contract]);

    foreach ([$landlord, $staff, $tenant] as $user) {
        $user->switchTeam($landlord->currentTeam);
        $this->actingAs($user)->get($url)->assertOk()->assertSee('Rental Agreement')->assertSee('has not agreed');
    }

    $roommate->switchTeam($landlord->currentTeam);
    $this->actingAs($roommate)->get($url)->assertForbidden();

    $outsider = User::factory()->create();
    $outsider->switchTeam($outsider->currentTeam);
    $this->actingAs($outsider)
        ->get(route('contracts.print', ['contract' => $contract, 'current_team' => $outsider->currentTeam->slug]))
        ->assertNotFound();

    auth()->logout();
    $this->get($url)->assertRedirect(route('login'));
});

test('a landlord creates a contract for a tenant who moved in before contracts existed, and sees its status', function () {
    Notification::fake();
    [$landlord, $tenant, $unit] = contractSetup();
    $lease = Lease::factory()->for($unit)->for($tenant, 'tenant')->create(['status' => LeaseStatus::Active]);
    $landlord->switchTeam($landlord->currentTeam);

    $this->actingAs($landlord)->get(route('leases.contract', ['lease' => $lease]))->assertOk()->assertSee('Not made yet');

    Livewire::actingAs($landlord)
        ->test('pages::landlord.lease-contract', ['lease' => $lease->id])
        ->call('generate')
        ->assertSee('Waiting for the tenant')
        ->assertSee('Unit 101 of Sunrise Apartments');

    expect($lease->fresh()->contract)->not->toBeNull();
    Notification::assertSentTo($tenant, ContractReady::class);

    Livewire::actingAs($landlord)->test('pages::landlord.tenants')
        ->call('manageTenant', $tenant->id)
        ->assertSee('Waiting for the tenant')
        ->assertSeeHtml('href="'.route('leases.contract', ['lease' => $lease]).'"');
});

test('an agreed contract shows as agreed and offers no way to make it again', function () {
    [$landlord, $tenant, $unit] = contractSetup();
    $lease = Lease::factory()->for($unit)->for($tenant, 'tenant')->create(['status' => LeaseStatus::Active]);
    LeaseContract::factory()->for($lease)->accepted()->create();

    Livewire::actingAs($landlord)
        ->test('pages::landlord.lease-contract', ['lease' => $lease->id])
        ->assertSee('Agreed')
        ->assertDontSee('Make again from current terms')
        ->call('generate')
        ->assertForbidden();
});

test('staff can read a tenant\'s contract but not make one, and other teams\' leases are not found', function () {
    [$landlord, $tenant, $unit] = contractSetup();
    $lease = Lease::factory()->for($unit)->for($tenant, 'tenant')->create(['status' => LeaseStatus::Active]);
    $staff = User::factory()->create();
    $landlord->currentTeam->members()->attach($staff, ['role' => TeamRole::Member]);
    $staff->switchTeam($landlord->currentTeam);

    Livewire::actingAs($staff)
        ->test('pages::landlord.lease-contract', ['lease' => $lease->id])
        ->assertDontSee('Create contract')
        ->call('generate')
        ->assertForbidden();

    expect($lease->fresh()->contract)->toBeNull();

    $tenant->switchTeam($landlord->currentTeam);
    $this->actingAs($tenant)->get(route('leases.contract', ['lease' => $lease]))->assertForbidden();

    $outsider = User::factory()->create();

    expect(fn () => Livewire::actingAs($outsider)->test('pages::landlord.lease-contract', ['lease' => $lease->id]))
        ->toThrow(ModelNotFoundException::class);
});
