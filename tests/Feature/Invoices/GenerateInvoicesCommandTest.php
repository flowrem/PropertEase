<?php

use App\Enums\LeaseStatus;
use App\Models\Lease;

test('the invoices generate command creates invoices for active leases and skips inactive ones', function () {
    $activeLease = Lease::factory()->create([
        'status' => LeaseStatus::Active,
        'start_date' => today()->subDay(),
        'due_day' => 15,
    ]);
    $activeLease->rents()->create(['amount' => 3000, 'effective_date' => $activeLease->start_date]);

    $endedLease = Lease::factory()->create(['status' => LeaseStatus::Ended]);

    $this->artisan('invoices:generate')->assertExitCode(0);

    $this->assertDatabaseHas('invoices', ['lease_id' => $activeLease->id]);
    $this->assertDatabaseMissing('invoices', ['lease_id' => $endedLease->id]);
});
