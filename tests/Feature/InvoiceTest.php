<?php

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Models\Invoice;

test('balance due subtracts every payment from the total', function () {
    $invoice = Invoice::factory()->create(['total_amount' => 5000, 'status' => InvoiceStatus::PartiallyPaid]);
    $invoice->payments()->create(['amount_paid' => 2000, 'method' => PaymentMethod::Cash, 'paid_at' => now()]);

    $invoice->load('payments');

    expect($invoice->balanceDue())->toBe(3000.0);
});

test('an invoice with no payments owes its full total', function () {
    $invoice = Invoice::factory()->create(['total_amount' => 5000]);
    $invoice->load('payments');

    expect($invoice->balanceDue())->toBe(5000.0);
});

test('an unpaid invoice past its due date is past due', function () {
    $invoice = Invoice::factory()->create([
        'status' => InvoiceStatus::Unpaid,
        'due_date' => today()->subDay(),
    ]);

    expect($invoice->isPastDue())->toBeTrue();
});

test('an unpaid invoice due today is not yet past due', function () {
    $invoice = Invoice::factory()->create([
        'status' => InvoiceStatus::Unpaid,
        'due_date' => today(),
    ]);

    expect($invoice->isPastDue())->toBeFalse();
});

test('a paid invoice past its due date is not past due', function () {
    $invoice = Invoice::factory()->create([
        'status' => InvoiceStatus::Paid,
        'due_date' => today()->subDay(),
    ]);

    expect($invoice->isPastDue())->toBeFalse();
});

test('the outstanding scope includes unpaid, partially paid, and overdue invoices only', function () {
    Invoice::factory()->create(['status' => InvoiceStatus::Unpaid]);
    Invoice::factory()->create(['status' => InvoiceStatus::PartiallyPaid]);
    Invoice::factory()->create(['status' => InvoiceStatus::Overdue]);
    Invoice::factory()->create(['status' => InvoiceStatus::Paid]);

    expect(Invoice::outstanding()->count())->toBe(3)
        ->and(Invoice::outstanding()->pluck('status'))->not->toContain(InvoiceStatus::Paid);
});
