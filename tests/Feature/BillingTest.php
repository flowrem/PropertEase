<?php

use App\Enums\InvoiceStatus;
use App\Enums\LeaseStatus;
use App\Enums\TeamRole;
use App\Enums\UnitStatus;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $user = User::factory()->create();

    $response = $this->get(route('billing'));

    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the billing page', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('billing'));

    $response->assertOk();
});

test('a tenant sees their outstanding invoice and balance', function () {
    $landlord = User::factory()->create();
    $property = Property::factory()->for($landlord->currentTeam)->create();
    $unit = Unit::factory()->for($property)->create(['unit_number' => '204', 'status' => UnitStatus::Occupied]);

    $tenant = User::factory()->create();
    $landlord->currentTeam->members()->attach($tenant, ['role' => TeamRole::Tenant]);
    $tenant->switchTeam($landlord->currentTeam);
    $lease = Lease::factory()->for($unit)->for($tenant, 'tenant')->create(['status' => LeaseStatus::Active]);
    Invoice::factory()->for($lease)->create([
        'total_amount' => 3500,
        'due_date' => today()->addDays(5),
        'status' => InvoiceStatus::Unpaid,
    ]);

    $this->actingAs($tenant);

    Livewire::test('pages::billing')
        ->assertSee('Unit 204')
        ->assertSee('3,500.00')
        ->assertDontSee('Overdue');
});

test('a tenant sees an unpaid invoice past its due date as overdue', function () {
    $landlord = User::factory()->create();
    $property = Property::factory()->for($landlord->currentTeam)->create();
    $unit = Unit::factory()->for($property)->create(['status' => UnitStatus::Occupied]);

    $tenant = User::factory()->create();
    $landlord->currentTeam->members()->attach($tenant, ['role' => TeamRole::Tenant]);
    $tenant->switchTeam($landlord->currentTeam);
    $lease = Lease::factory()->for($unit)->for($tenant, 'tenant')->create(['status' => LeaseStatus::Active]);
    Invoice::factory()->for($lease)->create([
        'due_date' => today()->subDays(3),
        'status' => InvoiceStatus::Unpaid,
    ]);

    $this->actingAs($tenant);

    Livewire::test('pages::billing')->assertSee('Overdue');
});

test('a tenant sees their paid invoices in payment history', function () {
    $landlord = User::factory()->create();
    $property = Property::factory()->for($landlord->currentTeam)->create();
    $unit = Unit::factory()->for($property)->create(['status' => UnitStatus::Occupied]);

    $tenant = User::factory()->create();
    $landlord->currentTeam->members()->attach($tenant, ['role' => TeamRole::Tenant]);
    $tenant->switchTeam($landlord->currentTeam);
    $lease = Lease::factory()->for($unit)->for($tenant, 'tenant')->create(['status' => LeaseStatus::Active]);
    $invoice = Invoice::factory()->for($lease)->create(['total_amount' => 3000, 'status' => InvoiceStatus::Paid]);
    $invoice->payments()->create([
        'amount_paid' => 3000,
        'method' => 'cash',
        'paid_at' => today()->subMonth(),
    ]);

    $this->actingAs($tenant);

    Livewire::test('pages::billing')
        ->assertDontSee('No payments yet.')
        ->assertSee('3,000.00');
});

test('a tenant does not see another tenant\'s invoices', function () {
    $landlord = User::factory()->create();
    $property = Property::factory()->for($landlord->currentTeam)->create();
    $unitA = Unit::factory()->for($property)->create(['unit_number' => '101', 'status' => UnitStatus::Occupied]);
    $unitB = Unit::factory()->for($property)->create(['unit_number' => '102', 'status' => UnitStatus::Occupied]);

    $tenant = User::factory()->create();
    $landlord->currentTeam->members()->attach($tenant, ['role' => TeamRole::Tenant]);
    $tenant->switchTeam($landlord->currentTeam);
    $lease = Lease::factory()->for($unitA)->for($tenant, 'tenant')->create(['status' => LeaseStatus::Active]);
    Invoice::factory()->for($lease)->create(['status' => InvoiceStatus::Unpaid]);

    $otherTenant = User::factory()->create();
    $landlord->currentTeam->members()->attach($otherTenant, ['role' => TeamRole::Tenant]);
    $otherLease = Lease::factory()->for($unitB)->for($otherTenant, 'tenant')->create(['status' => LeaseStatus::Active]);
    Invoice::factory()->for($otherLease)->create(['status' => InvoiceStatus::Unpaid]);

    $this->actingAs($tenant);

    Livewire::test('pages::billing')
        ->assertSee('Unit 101')
        ->assertDontSee('Unit 102');
});
