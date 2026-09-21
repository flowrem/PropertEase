<?php

use App\Enums\InvoiceStatus;
use App\Enums\LeaseStatus;
use App\Enums\PaymentMethod;
use App\Enums\TeamRole;
use App\Enums\UnitStatus;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $user = User::factory()->create();

    $response = $this->get(route('invoices'));

    $response->assertRedirect(route('login'));
});

test('tenants cannot access the invoices page', function () {
    $landlord = User::factory()->create();
    $tenant = User::factory()->create();

    $landlord->currentTeam->members()->attach($tenant, ['role' => TeamRole::Tenant]);
    $tenant->switchTeam($landlord->currentTeam);

    $response = $this->actingAs($tenant)->get(route('invoices'));

    $response->assertForbidden();
});

test('a landlord sees a tenant\'s outstanding balance', function () {
    $landlord = User::factory()->create();
    $property = Property::factory()->for($landlord->currentTeam)->create(['name' => 'Sunrise Apartments']);
    $unit = Unit::factory()->for($property)->create(['unit_number' => '204', 'status' => UnitStatus::Occupied]);

    $tenant = User::factory()->create(['name' => 'Juana Dela Cruz']);
    $landlord->currentTeam->members()->attach($tenant, ['role' => TeamRole::Tenant]);
    $lease = Lease::factory()->for($unit)->for($tenant, 'tenant')->create(['status' => LeaseStatus::Active]);
    Invoice::factory()->for($lease)->create(['total_amount' => 3500, 'status' => InvoiceStatus::Unpaid]);

    $this->actingAs($landlord);

    Livewire::test('pages::landlord.invoices')
        ->assertSee('Juana Dela Cruz')
        ->assertSee('3,500.00');
});

test('a tenant with no invoices does not appear in the ledger', function () {
    $landlord = User::factory()->create();
    $tenant = User::factory()->create(['name' => 'Juana Dela Cruz']);
    $landlord->currentTeam->members()->attach($tenant, ['role' => TeamRole::Tenant]);

    $this->actingAs($landlord);

    Livewire::test('pages::landlord.invoices')
        ->assertDontSee('Juana Dela Cruz');
});

test('a landlord can filter the ledger by property or unit number', function () {
    $landlord = User::factory()->create();
    $property = Property::factory()->for($landlord->currentTeam)->create(['name' => 'Sunrise Apartments']);
    $unit = Unit::factory()->for($property)->create(['unit_number' => '204', 'status' => UnitStatus::Occupied]);

    $tenant = User::factory()->create(['name' => 'Juana Dela Cruz']);
    $landlord->currentTeam->members()->attach($tenant, ['role' => TeamRole::Tenant]);
    $lease = Lease::factory()->for($unit)->for($tenant, 'tenant')->create(['status' => LeaseStatus::Active]);
    Invoice::factory()->for($lease)->create(['status' => InvoiceStatus::Unpaid]);

    $otherTenant = User::factory()->create(['name' => 'Pedro Reyes']);
    $landlord->currentTeam->members()->attach($otherTenant, ['role' => TeamRole::Tenant]);
    $otherProperty = Property::factory()->for($landlord->currentTeam)->create(['name' => 'Palm Villas']);
    $otherUnit = Unit::factory()->for($otherProperty)->create(['unit_number' => '99', 'status' => UnitStatus::Occupied]);
    $otherLease = Lease::factory()->for($otherUnit)->for($otherTenant, 'tenant')->create(['status' => LeaseStatus::Active]);
    Invoice::factory()->for($otherLease)->create(['status' => InvoiceStatus::Unpaid]);

    $this->actingAs($landlord);

    Livewire::test('pages::landlord.invoices')
        ->set('search', 'sunrise')
        ->assertSee('Juana Dela Cruz')
        ->assertDontSee('Pedro Reyes')
        ->set('search', '204')
        ->assertSee('Juana Dela Cruz')
        ->assertDontSee('Pedro Reyes');
});

test('opening a tenant splits their invoices into outstanding and history', function () {
    $landlord = User::factory()->create();
    $property = Property::factory()->for($landlord->currentTeam)->create();
    $unit = Unit::factory()->for($property)->create(['status' => UnitStatus::Occupied]);

    $tenant = User::factory()->create();
    $landlord->currentTeam->members()->attach($tenant, ['role' => TeamRole::Tenant]);
    $lease = Lease::factory()->for($unit)->for($tenant, 'tenant')->create(['status' => LeaseStatus::Active]);

    Invoice::factory()->for($lease)->create(['total_amount' => 3000, 'status' => InvoiceStatus::Unpaid]);
    Invoice::factory()->for($lease)->create(['total_amount' => 3000, 'status' => InvoiceStatus::Paid]);

    $this->actingAs($landlord);

    Livewire::test('pages::landlord.invoices')
        ->call('viewTenant', $tenant->id)
        ->assertSee('Outstanding')
        ->assertSee('History')
        ->assertDontSee('Nothing outstanding.')
        ->assertDontSee('No payments yet.');
});

test('a landlord can record a full payment, moving the invoice to history', function () {
    $landlord = User::factory()->create();
    $property = Property::factory()->for($landlord->currentTeam)->create();
    $unit = Unit::factory()->for($property)->create(['status' => UnitStatus::Occupied]);

    $tenant = User::factory()->create();
    $landlord->currentTeam->members()->attach($tenant, ['role' => TeamRole::Tenant]);
    $lease = Lease::factory()->for($unit)->for($tenant, 'tenant')->create(['status' => LeaseStatus::Active]);
    $invoice = Invoice::factory()->for($lease)->create(['total_amount' => 3000, 'status' => InvoiceStatus::Unpaid]);

    $this->actingAs($landlord);

    Livewire::test('pages::landlord.invoices')
        ->call('confirmRecordPayment', $invoice->id)
        ->set('method', PaymentMethod::Gcash->value)
        ->set('reference_number', 'REF-123')
        ->call('recordPayment')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('payments', [
        'invoice_id' => $invoice->id,
        'amount_paid' => 3000,
        'method' => PaymentMethod::Gcash->value,
        'reference_number' => 'REF-123',
    ]);

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
});

test('a landlord can record a partial payment, keeping the invoice outstanding', function () {
    $landlord = User::factory()->create();
    $property = Property::factory()->for($landlord->currentTeam)->create();
    $unit = Unit::factory()->for($property)->create(['status' => UnitStatus::Occupied]);

    $tenant = User::factory()->create();
    $landlord->currentTeam->members()->attach($tenant, ['role' => TeamRole::Tenant]);
    $lease = Lease::factory()->for($unit)->for($tenant, 'tenant')->create(['status' => LeaseStatus::Active]);
    $invoice = Invoice::factory()->for($lease)->create(['total_amount' => 3000, 'status' => InvoiceStatus::Unpaid]);

    $this->actingAs($landlord);

    Livewire::test('pages::landlord.invoices')
        ->call('confirmRecordPayment', $invoice->id)
        ->set('amount', '1000')
        ->set('method', PaymentMethod::Cash->value)
        ->call('recordPayment')
        ->assertHasNoErrors();

    $invoice->refresh()->load('payments');

    expect($invoice->status)->toBe(InvoiceStatus::PartiallyPaid)
        ->and($invoice->balanceDue())->toBe(2000.0);
});

test('recording a payment requires an amount and a method', function () {
    $landlord = User::factory()->create();
    $property = Property::factory()->for($landlord->currentTeam)->create();
    $unit = Unit::factory()->for($property)->create(['status' => UnitStatus::Occupied]);

    $tenant = User::factory()->create();
    $landlord->currentTeam->members()->attach($tenant, ['role' => TeamRole::Tenant]);
    $lease = Lease::factory()->for($unit)->for($tenant, 'tenant')->create(['status' => LeaseStatus::Active]);
    $invoice = Invoice::factory()->for($lease)->create(['total_amount' => 3000, 'status' => InvoiceStatus::Unpaid]);

    $this->actingAs($landlord);

    Livewire::test('pages::landlord.invoices')
        ->call('confirmRecordPayment', $invoice->id)
        ->set('amount', '')
        ->set('method', '')
        ->call('recordPayment')
        ->assertHasErrors(['amount' => 'required', 'method' => 'required']);
});

test('recording a payment cannot exceed the invoice balance', function () {
    $landlord = User::factory()->create();
    $property = Property::factory()->for($landlord->currentTeam)->create();
    $unit = Unit::factory()->for($property)->create(['status' => UnitStatus::Occupied]);

    $tenant = User::factory()->create();
    $landlord->currentTeam->members()->attach($tenant, ['role' => TeamRole::Tenant]);
    $lease = Lease::factory()->for($unit)->for($tenant, 'tenant')->create(['status' => LeaseStatus::Active]);
    $invoice = Invoice::factory()->for($lease)->create(['total_amount' => 3000, 'status' => InvoiceStatus::Unpaid]);

    $this->actingAs($landlord);

    Livewire::test('pages::landlord.invoices')
        ->call('confirmRecordPayment', $invoice->id)
        ->set('amount', '5000')
        ->set('method', PaymentMethod::Cash->value)
        ->call('recordPayment')
        ->assertHasErrors(['amount' => 'max']);

    $this->assertDatabaseCount('payments', 0);
});

test('a landlord cannot record a payment against another team\'s invoice', function () {
    $landlord = User::factory()->create();
    $otherLandlord = User::factory()->create();
    $otherProperty = Property::factory()->for($otherLandlord->currentTeam)->create();
    $otherUnit = Unit::factory()->for($otherProperty)->create(['status' => UnitStatus::Occupied]);
    $otherTenant = User::factory()->create();
    $otherLandlord->currentTeam->members()->attach($otherTenant, ['role' => TeamRole::Tenant]);
    $otherLease = Lease::factory()->for($otherUnit)->for($otherTenant, 'tenant')->create(['status' => LeaseStatus::Active]);
    $otherInvoice = Invoice::factory()->for($otherLease)->create(['status' => InvoiceStatus::Unpaid]);

    $this->actingAs($landlord);

    $this->expectException(ModelNotFoundException::class);

    Livewire::test('pages::landlord.invoices')
        ->call('confirmRecordPayment', $otherInvoice->id);
});
