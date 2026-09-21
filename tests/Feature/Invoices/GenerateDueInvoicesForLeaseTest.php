<?php

use App\Actions\Invoices\GenerateDueInvoicesForLease;
use App\Enums\BillingTiming;
use App\Enums\InvoiceStatus;
use App\Enums\LeaseStatus;
use App\Models\Lease;
use Carbon\CarbonImmutable;

test('the first invoice prorates rent from the lease start date to its first due day', function () {
    $lease = Lease::factory()->create([
        'status' => LeaseStatus::Active,
        'start_date' => '2026-01-10',
        'due_day' => 25,
        'billing_timing' => BillingTiming::Advance,
    ]);
    $lease->rents()->create(['amount' => 3100, 'effective_date' => '2026-01-10']);

    $invoices = (new GenerateDueInvoicesForLease)->handle($lease, CarbonImmutable::parse('2026-01-10'));

    expect($invoices)->toHaveCount(1);

    $invoice = $invoices->first();

    expect($invoice->billing_start->toDateString())->toBe('2026-01-10')
        ->and($invoice->billing_end->toDateString())->toBe('2026-01-24')
        ->and($invoice->due_date->toDateString())->toBe('2026-01-10')
        ->and($invoice->rent_amount)->toEqual('1500.00')
        ->and($invoice->total_amount)->toEqual('1500.00')
        ->and($invoice->status)->toBe(InvoiceStatus::Unpaid);
});

test('advance billing bills a cycle at its start, arrears billing bills it at its end', function () {
    $advanceLease = Lease::factory()->create([
        'status' => LeaseStatus::Active,
        'start_date' => '2026-01-10',
        'due_day' => 25,
        'billing_timing' => BillingTiming::Advance,
    ]);
    $advanceLease->rents()->create(['amount' => 3100, 'effective_date' => '2026-01-10']);

    $arrearsLease = Lease::factory()->create([
        'status' => LeaseStatus::Active,
        'start_date' => '2026-01-10',
        'due_day' => 25,
        'billing_timing' => BillingTiming::Arrears,
    ]);
    $arrearsLease->rents()->create(['amount' => 3100, 'effective_date' => '2026-01-10']);

    $action = new GenerateDueInvoicesForLease;
    $asOf = CarbonImmutable::parse('2026-01-10');

    $advanceInvoice = $action->handle($advanceLease, $asOf)->first();
    $arrearsInvoice = $action->handle($arrearsLease, $asOf)->first();

    expect($advanceInvoice->due_date->toDateString())->toBe('2026-01-10')
        ->and($arrearsInvoice->due_date->toDateString())->toBe('2026-01-25');
});

test('subsequent cycles bill the full monthly rent and are never duplicated', function () {
    $lease = Lease::factory()->create([
        'status' => LeaseStatus::Active,
        'start_date' => '2026-01-10',
        'due_day' => 25,
        'billing_timing' => BillingTiming::Advance,
    ]);
    $lease->rents()->create(['amount' => 3100, 'effective_date' => '2026-01-10']);

    $action = new GenerateDueInvoicesForLease;

    $first = $action->handle($lease, CarbonImmutable::parse('2026-01-25'));

    expect($first)->toHaveCount(2);

    $secondCycle = $first->last();

    expect($secondCycle->billing_start->toDateString())->toBe('2026-01-25')
        ->and($secondCycle->billing_end->toDateString())->toBe('2026-02-24')
        ->and($secondCycle->due_date->toDateString())->toBe('2026-01-25')
        ->and($secondCycle->rent_amount)->toEqual('3100.00');

    $again = $action->handle($lease, CarbonImmutable::parse('2026-01-25'));

    expect($again)->toHaveCount(0)
        ->and($lease->invoices()->count())->toBe(2);
});

test('each cycle bills whichever rent was effective at that cycle\'s start', function () {
    $lease = Lease::factory()->create([
        'status' => LeaseStatus::Active,
        'start_date' => '2026-01-10',
        'due_day' => 25,
        'billing_timing' => BillingTiming::Advance,
    ]);
    $lease->rents()->create(['amount' => 3000, 'effective_date' => '2026-01-10']);
    $lease->rents()->create(['amount' => 4000, 'effective_date' => '2026-01-25']);

    $invoices = (new GenerateDueInvoicesForLease)->handle($lease, CarbonImmutable::parse('2026-01-25'));

    [$stub, $secondCycle] = $invoices->all();

    expect($stub->rent_amount)->toEqual('1451.61')
        ->and($secondCycle->rent_amount)->toEqual('4000.00');
});

test('a lease that is not active generates no invoices', function () {
    $lease = Lease::factory()->create([
        'status' => LeaseStatus::Ended,
        'start_date' => '2026-01-10',
        'due_day' => 25,
    ]);
    $lease->rents()->create(['amount' => 3000, 'effective_date' => '2026-01-10']);

    $invoices = (new GenerateDueInvoicesForLease)->handle($lease, CarbonImmutable::parse('2026-02-01'));

    expect($invoices)->toHaveCount(0);
    $this->assertDatabaseCount('invoices', 0);
});
