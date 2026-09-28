<?php

use App\Enums\LeaseStatus;
use App\Enums\ReservationStatus;
use App\Enums\TransferStatus;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Reservation;
use App\Models\TransferRequest;
use App\Models\UnitListing;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('media');
    Storage::fake('sensitive');
});

test('the demo data sets up every feature for one landlord, and running it again changes nothing', function () {
    $this->seed(DemoSeeder::class);

    $landlord = User::query()->where('email', DemoSeeder::LANDLORD_EMAIL)->firstOrFail();
    $team = $landlord->currentTeam;
    $juana = User::query()->where('email', 'juana@demo.occuplace.test')->firstOrFail();
    $juanaLease = Lease::query()->where('tenant_id', $juana->id)->where('status', LeaseStatus::Active->value)->firstOrFail();

    expect($team->name)->toBe('Sunrise Residences')
        ->and($team->contractTemplate)->not->toBeNull()
        ->and($team->properties()->firstOrFail()->psgcCity?->name)->toBe('City of Lipa')
        ->and(UnitListing::query()->publiclyVisible()->count())->toBe(2)
        ->and($juanaLease->contract?->isAccepted())->toBeTrue()
        ->and($juanaLease->moveInCheck?->tenant_acknowledged_at)->not->toBeNull()
        ->and(Invoice::query()->where('lease_id', $juanaLease->id)->count())->toBeGreaterThanOrEqual(2)
        ->and($juanaLease->concerns()->count())->toBe(2)
        ->and(TransferRequest::query()->where('team_id', $team->id)->value('status'))->toBe(TransferStatus::Pending)
        ->and(Reservation::query()->where('team_id', $team->id)->pluck('status')->map->value->sort()->values()->all())
        ->toBe([ReservationStatus::Confirmed->value, ReservationStatus::Pending->value, ReservationStatus::Reserved->value, ReservationStatus::Reserved->value])
        ->and($landlord->notifications()->count())->toBeGreaterThan(0);

    $this->assertTrue(auth()->attempt(['email' => 'juana@demo.occuplace.test', 'password' => DemoSeeder::PASSWORD]));

    $users = User::count();
    $this->seed(DemoSeeder::class);

    expect(User::count())->toBe($users);
});

test('the demo data refuses to run in production', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => app(DemoSeeder::class)->setContainer(app())->__invoke())->toThrow(RuntimeException::class, 'local use only');
    expect(User::query()->where('email', DemoSeeder::LANDLORD_EMAIL)->exists())->toBeFalse();
});
