<?php

use App\Enums\ConcernCategory;
use App\Enums\ConcernPriority;
use App\Enums\ConcernStatus;
use App\Enums\InvoiceStatus;
use App\Enums\TeamRole;
use App\Enums\UnitStatus;
use App\Models\Concern;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/**
 * A landlord with a unit and an active lease, so the Home page renders (no setup redirect).
 *
 * @return array{0: User, 1: Lease}
 */
function landlordWithLease(): array
{
    $landlord = User::factory()->create();
    $unit = Unit::factory()->for(Property::factory()->for($landlord->currentTeam))->create(['status' => UnitStatus::Occupied]);
    $lease = Lease::factory()->for($unit)->create();

    return [$landlord, $lease];
}

function homeFor(User $landlord)
{
    return Livewire::actingAs($landlord)->test('pages::dashboard');
}

test('rent collected is summed per month, empty months included, and only for this team', function () {
    [$landlord, $lease] = landlordWithLease();
    [, $otherLease] = landlordWithLease();

    $thisMonth = CarbonImmutable::now()->startOfMonth();

    $invoice = Invoice::factory()->for($lease)->create();
    Payment::factory()->for($invoice)->create(['amount_paid' => 1000, 'paid_at' => $thisMonth->addDay()]);
    Payment::factory()->for($invoice)->create(['amount_paid' => 250.5, 'paid_at' => $thisMonth->addDays(3)]);
    Payment::factory()->for($invoice)->create(['amount_paid' => 4000, 'paid_at' => $thisMonth->subMonths(2)->addDay()]);
    Payment::factory()->for($invoice)->create(['amount_paid' => 9999, 'paid_at' => $thisMonth->subMonths(9)]);

    Payment::factory()->for(Invoice::factory()->for($otherLease)->create())->create(['amount_paid' => 777, 'paid_at' => $thisMonth->addDay()]);

    $points = homeFor($landlord)->instance()->rentCollectedPerMonth;

    expect($points)->toHaveCount(6)
        ->and($points[5]['value'])->toBe(1250.5)
        ->and($points[3]['value'])->toBe(4000.0)
        ->and($points[4]['value'])->toBe(0.0)
        ->and(collect($points)->sum('value'))->toBe(5250.5)
        ->and($points[5]['label'])->toBe($thisMonth->format('M'));
});

test('invoices are counted once each by where they stand now', function () {
    [$landlord, $lease] = landlordWithLease();
    [, $otherLease] = landlordWithLease();

    $future = now()->addWeek();
    $past = now()->subWeek();

    Invoice::factory()->for($lease)->create(['status' => InvoiceStatus::Paid, 'due_date' => $past]);
    Invoice::factory()->for($lease)->create(['status' => InvoiceStatus::PartiallyPaid, 'due_date' => $future]);
    Invoice::factory()->for($lease)->create(['status' => InvoiceStatus::Unpaid, 'due_date' => $future]);
    Invoice::factory()->for($lease)->create(['status' => InvoiceStatus::Unpaid, 'due_date' => $past]);
    Invoice::factory()->for($lease)->create(['status' => InvoiceStatus::PartiallyPaid, 'due_date' => $past]);
    Invoice::factory()->for($lease)->create(['status' => InvoiceStatus::Paid, 'due_date' => now()->subYear()]);
    Invoice::factory()->for($otherLease)->create(['status' => InvoiceStatus::Unpaid, 'due_date' => $past]);

    expect(homeFor($landlord)->instance()->invoiceStates)->toBe([
        'paid' => 1,
        'partial' => 1,
        'unpaid' => 1,
        'pastDue' => 2,
        'total' => 5,
    ]);
});

test('open maintenance is counted by priority, urgent first, ignoring resolved and complaints', function () {
    [$landlord, $lease] = landlordWithLease();
    [, $otherLease] = landlordWithLease();

    $concern = fn (Lease $for, ConcernPriority $priority, ConcernStatus $status = ConcernStatus::Pending, ConcernCategory $category = ConcernCategory::Maintenance) => Concern::factory()->for($for)->create([
        'priority' => $priority,
        'status' => $status,
        'category' => $category,
    ]);

    $concern($lease, ConcernPriority::Urgent);
    $concern($lease, ConcernPriority::Urgent, ConcernStatus::InProgress);
    $concern($lease, ConcernPriority::Low);
    $concern($lease, ConcernPriority::High, ConcernStatus::Resolved);
    $concern($lease, ConcernPriority::High, ConcernStatus::Pending, ConcernCategory::Complaint);
    $concern($otherLease, ConcernPriority::Urgent);

    $rows = homeFor($landlord)->instance()->openMaintenanceByPriority;

    expect(collect($rows)->pluck('label')->all())->toBe(['Urgent', 'High', 'Medium', 'Low'])
        ->and(collect($rows)->pluck('value')->all())->toBe([2, 0, 0, 1])
        ->and($rows[0]['emphasis'])->toBeTrue()
        ->and($rows[3]['emphasis'])->toBeFalse();
});

test('the Home page renders every landlord chart', function () {
    [$landlord] = landlordWithLease();

    homeFor($landlord)
        ->assertSee('Rent collected per month')
        ->assertSee('Invoices by state')
        ->assertSee('Open maintenance by priority')
        ->assertSee('Units occupied')
        ->assertSee('100%');
});

test('a landlord with no invoices or payments gets empty states, not broken charts', function () {
    [$landlord] = landlordWithLease();

    homeFor($landlord)
        ->assertSee('Nothing to show yet.')
        ->assertSee('No payments recorded in the last 6 months.');
});

test('tenants do not see landlord charts', function () {
    [$landlord] = landlordWithLease();
    $tenant = User::factory()->create();
    $landlord->currentTeam->members()->attach($tenant, ['role' => TeamRole::Tenant]);
    $tenant->switchTeam($landlord->currentTeam);

    homeFor($tenant)
        ->assertDontSee('Rent collected per month')
        ->assertDontSee('Invoices by state');
});
