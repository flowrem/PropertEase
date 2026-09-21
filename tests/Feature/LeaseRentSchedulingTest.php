<?php

use App\Models\Lease;
use App\Models\Unit;

test('a rent change is scheduled for next month once the due day has already passed this month', function () {
    $this->travelTo(today()->day(20));

    $lease = new Lease(['due_day' => 5]);

    expect($lease->nextRentEffectiveDate()->toDateString())
        ->toBe(today()->addMonthNoOverflow()->day(5)->toDateString());
});

test('a rent change is scheduled within the current month when the due day is still ahead', function () {
    $this->travelTo(today()->day(5));

    $lease = new Lease(['due_day' => 20]);

    expect($lease->nextRentEffectiveDate()->toDateString())
        ->toBe(today()->day(20)->toDateString());
});

test('a rent change rolls to next month when today is exactly the due day', function () {
    $this->travelTo(today()->day(10));

    $lease = new Lease(['due_day' => 10]);

    expect($lease->nextRentEffectiveDate()->toDateString())
        ->toBe(today()->addMonthNoOverflow()->day(10)->toDateString());
});

test('a lease only treats rent effective on or before today as its current rent', function () {
    $unit = Unit::factory()->create(['price' => 3000]);
    $lease = Lease::factory()->for($unit)->create();

    $lease->rents()->create(['amount' => 3000, 'effective_date' => today()->subMonth()]);
    $future = $lease->rents()->create(['amount' => 3500, 'effective_date' => today()->addWeek()]);

    expect($lease->currentRent->amount)->toEqual('3000.00');

    $this->travelTo($future->effective_date);

    expect($lease->fresh()->currentRent->amount)->toEqual('3500.00');
});
