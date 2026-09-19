<?php

use App\Enums\ConcernCategory;
use App\Enums\ConcernStatus;
use App\Models\Concern;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Team;
use App\Models\Unit;
use App\Models\User;

test('a lease\'s billing chain resolves back to its property and team', function () {
    $team = Team::factory()->create();
    $property = Property::factory()->for($team)->create();
    $unit = Unit::factory()->for($property)->create();
    $lease = Lease::factory()->for($unit)->create();
    $invoice = Invoice::factory()->for($lease)->create();
    $payment = Payment::factory()->for($invoice)->create();

    expect($payment->invoice->lease->unit->property->team->is($team))->toBeTrue();
});

test('maintenance and complaint concerns on the same lease stay in separate categories', function () {
    $lease = Lease::factory()->create();

    $maintenance = Concern::factory()->for($lease)->create(['category' => ConcernCategory::Maintenance]);
    $complaint = Concern::factory()->for($lease)->create(['category' => ConcernCategory::Complaint]);

    expect($lease->concerns)->toHaveCount(2)
        ->and($lease->concerns->where('category', ConcernCategory::Maintenance)->first()->is($maintenance))->toBeTrue()
        ->and($lease->concerns->where('category', ConcernCategory::Complaint)->first()->is($complaint))->toBeTrue();
});

test('a concern update records its author and can change the concern status', function () {
    $author = User::factory()->create();
    $concern = Concern::factory()->create(['status' => ConcernStatus::Pending]);

    $update = $concern->updates()->create([
        'author_id' => $author->id,
        'message' => 'Plumber scheduled for tomorrow.',
        'new_status' => ConcernStatus::InProgress,
    ]);

    expect($update->author->is($author))->toBeTrue()
        ->and($update->new_status)->toBe(ConcernStatus::InProgress);
});

test('deleting a lease cascades to its invoices, payments, and concerns', function () {
    $lease = Lease::factory()->create();
    $invoice = Invoice::factory()->for($lease)->create();
    $payment = Payment::factory()->for($invoice)->create();
    $concern = Concern::factory()->for($lease)->create();

    $lease->delete();

    expect(Invoice::find($invoice->id))->toBeNull()
        ->and(Payment::find($payment->id))->toBeNull()
        ->and(Concern::find($concern->id))->toBeNull();
});
