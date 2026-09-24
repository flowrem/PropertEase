<?php

use App\Enums\ReservationStatus;
use App\Enums\UnitStatus;
use App\Models\Reservation;
use App\Models\Team;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

function dashboardAdmin(): User
{
    $admin = User::factory()->create();
    $admin->forceFill(['is_super_admin' => true])->save();

    return $admin;
}

test('signups are counted in the week they happened, with empty weeks included', function () {
    $thisWeek = CarbonImmutable::now()->startOfWeek();

    Team::factory()->count(2)->create(['created_at' => $thisWeek->addDay()]);
    Team::factory()->create(['created_at' => $thisWeek->subWeeks(3)->addDays(2)]);
    Team::factory()->create(['created_at' => $thisWeek->subWeeks(20)]);

    $points = Livewire::actingAs(dashboardAdmin())
        ->test('pages::admin.dashboard')
        ->instance()->newLandlordsPerWeek;

    expect($points)->toHaveCount(12)
        ->and($points[11]['value'])->toBe(2 + 1)
        ->and($points[8]['value'])->toBe(1)
        ->and(collect($points)->sum('value'))->toBe(3 + 1)
        ->and($points[0]['label'])->toBe($thisWeek->subWeeks(11)->format('M j'));
});

test('reservations are bucketed by week too', function () {
    Reservation::factory()->status(ReservationStatus::Pending)->create(['created_at' => now()->subWeeks(2)]);

    $points = Livewire::actingAs(dashboardAdmin())
        ->test('pages::admin.dashboard')
        ->instance()->reservationsPerWeek;

    expect($points[9]['value'])->toBe(1);
});

test('the chart axis tops out at a clean number with a whole-number midpoint', function (int $peak, string $ceiling, string $middle) {
    Team::factory()->count($peak)->create(['created_at' => now()]);

    $html = (string) Livewire::actingAs(dashboardAdmin())
        ->test('pages::admin.dashboard')
        ->html();

    expect(preg_match('/'.$ceiling.'<\/span>\s*<span>'.$middle.'<\/span>\s*<span>0<\/span>/', $html))->toBe(1);
})->with([
    'one' => [1, '2', '1'],
    'three' => [3, '4', '2'],
    'seven' => [7, '10', '5'],
    'thirteen' => [13, '20', '10'],
]);

test('occupancy is computed from unit status', function () {
    Unit::factory()->count(3)->create(['status' => UnitStatus::Occupied]);
    Unit::factory()->create(['status' => UnitStatus::Vacant]);
    Unit::factory()->create(['status' => UnitStatus::UnderMaintenance]);

    $occupancy = Livewire::actingAs(dashboardAdmin())
        ->test('pages::admin.dashboard')
        ->instance()->occupancy;

    expect($occupancy)->toBe(['total' => 5, 'occupied' => 3, 'vacant' => 1, 'maintenance' => 1]);
});

test('an empty platform shows honest empty states instead of broken charts', function () {
    Livewire::actingAs(dashboardAdmin())
        ->test('pages::admin.dashboard')
        ->assertSee('Nothing is waiting on you.')
        ->assertSee('No units yet.')
        ->assertSee('Nothing recorded in this period.');
});

test('the review hero counts landlords and listings that need a decision', function () {
    Team::factory()->awaitingApproval()->count(2)->create();

    $needsReview = Livewire::actingAs(dashboardAdmin())
        ->test('pages::admin.dashboard')
        ->assertDontSee('Nothing is waiting on you.')
        ->instance()->needsReview;

    expect($needsReview)->toBe(['landlords' => 2, 'listings' => 0, 'total' => 2]);
});
