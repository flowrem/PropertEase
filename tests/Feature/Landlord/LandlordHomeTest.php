<?php

use App\Enums\ConcernCategory;
use App\Enums\ConcernPriority;
use App\Enums\ConcernStatus;
use App\Enums\InvoiceStatus;
use App\Enums\LeaseStatus;
use App\Enums\UnitStatus;
use App\Models\Concern;
use App\Models\ConcernUpdate;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Property;
use App\Models\Team;
use App\Models\Unit;
use App\Models\User;
use Livewire\Livewire;

function homeLease(Team $team): Lease
{
    $unit = Unit::factory()->for(Property::factory()->for($team)->create())->create();

    return Lease::factory()->for($unit)->create(['status' => LeaseStatus::Active]);
}

function landlordWithProperty(): User
{
    $landlord = User::factory()->create();
    Property::factory()->for($landlord->currentTeam)->create();

    return $landlord;
}

test('the landlord home links to the split maintenance and complaints pages instead of an inbox', function () {
    $landlord = landlordWithProperty();

    $landlord->switchTeam($landlord->currentTeam);

    $this->actingAs($landlord)->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('landlord.maintenance'), false)
        ->assertSee(route('landlord.complaints'), false)
        ->assertSee('Money owed')
        ->assertSee('Occupancy');
});

test('money owed totals only this team, splitting past due from outstanding', function () {
    $landlord = landlordWithProperty();
    $lease = homeLease($landlord->currentTeam);

    Invoice::factory()->for($lease)->create(['status' => InvoiceStatus::Unpaid, 'total_amount' => 1000, 'due_date' => today()->subDays(3)]);
    Invoice::factory()->for($lease)->create(['status' => InvoiceStatus::Unpaid, 'total_amount' => 500, 'due_date' => today()->addDays(5)]);
    Invoice::factory()->for($lease)->create(['status' => InvoiceStatus::Paid, 'total_amount' => 9999, 'due_date' => today()->subDays(9)]);
    Invoice::factory()->for(homeLease(Team::factory()->create()))->create(['status' => InvoiceStatus::Unpaid, 'total_amount' => 7777, 'due_date' => today()->subDays(2)]);

    $this->actingAs($landlord);

    $money = Livewire::test('pages::dashboard')->instance()->money;

    expect($money['pastDue'])->toBe(1000.0)
        ->and($money['outstanding'])->toBe(1500.0)
        ->and($money['behind'])->toHaveCount(1)
        ->and($money['behind'][0]['name'])->toBe($lease->tenant->name);
});

test('a payment reduces what is owed', function () {
    $landlord = landlordWithProperty();
    $lease = homeLease($landlord->currentTeam);
    $invoice = Invoice::factory()->for($lease)->create(['status' => InvoiceStatus::PartiallyPaid, 'total_amount' => 1000, 'due_date' => today()->subDay()]);
    $invoice->payments()->create(['amount_paid' => 400, 'method' => 'cash', 'paid_at' => now()]);

    $this->actingAs($landlord);

    expect(Livewire::test('pages::dashboard')->instance()->money['pastDue'])->toBe(600.0);
});

test('needs attention lists urgent maintenance and unanswered complaints only', function () {
    $landlord = landlordWithProperty();
    $lease = homeLease($landlord->currentTeam);

    Concern::factory()->for($lease)->create(['category' => ConcernCategory::Maintenance, 'priority' => ConcernPriority::Urgent, 'status' => ConcernStatus::Pending, 'title' => 'Burst pipe']);
    Concern::factory()->for($lease)->create(['category' => ConcernCategory::Maintenance, 'priority' => ConcernPriority::Low, 'status' => ConcernStatus::Pending, 'title' => 'Squeaky door']);
    Concern::factory()->for($lease)->create(['category' => ConcernCategory::Maintenance, 'priority' => ConcernPriority::Urgent, 'status' => ConcernStatus::Resolved, 'title' => 'Old fixed leak']);
    Concern::factory()->for($lease)->create(['category' => ConcernCategory::Complaint, 'status' => ConcernStatus::Pending, 'title' => 'Ignored complaint']);
    $answered = Concern::factory()->for($lease)->create(['category' => ConcernCategory::Complaint, 'status' => ConcernStatus::Pending, 'title' => 'Answered complaint']);
    ConcernUpdate::factory()->create(['concern_id' => $answered->id]);

    $landlord->switchTeam($landlord->currentTeam);

    $this->actingAs($landlord)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Burst pipe')
        ->assertDontSee('Squeaky door')
        ->assertDontSee('Old fixed leak')
        ->assertSee('Ignored complaint')
        ->assertDontSee('Answered complaint');
});

test('other teams concerns never appear on the home page', function () {
    $landlord = landlordWithProperty();
    $other = homeLease(Team::factory()->create());
    Concern::factory()->for($other)->create(['category' => ConcernCategory::Maintenance, 'priority' => ConcernPriority::Urgent, 'status' => ConcernStatus::Pending, 'title' => 'Someone elses flood']);

    $landlord->switchTeam($landlord->currentTeam);

    $this->actingAs($landlord)->get(route('dashboard'))->assertOk()->assertDontSee('Someone elses flood');
});

test('occupancy counts units by status and open slots across only this team', function () {
    $landlord = landlordWithProperty();
    $property = Property::factory()->for($landlord->currentTeam)->create();
    Unit::factory()->for($property)->create(['status' => UnitStatus::Vacant]);
    Unit::factory()->for($property)->create(['status' => UnitStatus::Vacant]);
    $occupied = Unit::factory()->for($property)->create(['status' => UnitStatus::Occupied]);
    Lease::factory()->for($occupied)->create(['status' => LeaseStatus::Active]);
    Unit::factory()->for($property)->create(['status' => UnitStatus::UnderMaintenance]);
    Unit::factory()->for(Property::factory()->for(Team::factory()->create())->create())->create(['status' => UnitStatus::Vacant]);

    $this->actingAs($landlord);

    $occupancy = Livewire::test('pages::dashboard')->instance()->occupancy;

    expect($occupancy['occupied'])->toBe(1)
        ->and($occupancy['vacant'])->toBe(2)
        ->and($occupancy['maintenance'])->toBe(1)
        ->and($occupancy['openSlots'])->toBe(2);
});
