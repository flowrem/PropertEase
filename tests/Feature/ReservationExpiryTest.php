<?php

use App\Enums\ReservationStatus;
use App\Enums\UnitStatus;
use App\Models\Reservation;
use App\Models\Unit;
use App\Notifications\ReservationExpired;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

test('the job expires reservations past their deadline with nothing sent, and emails each applicant once', function () {
    Notification::fake();
    $overdue = Reservation::factory()->for(Unit::factory()->state(['status' => UnitStatus::Vacant]))->reserved(now()->subMinute())->create();
    $withinDeadline = Reservation::factory()->reserved(now()->addHour())->create();
    $sentInTime = Reservation::factory()->reserved(now()->subDay())->downpaymentSent()->create();
    $confirmed = Reservation::factory()->confirmed()->create(['expires_at' => now()->subDay()]);
    $pending = Reservation::factory()->create();

    $this->artisan('reservations:expire')->expectsOutput('Expired 1 reservation(s).')->assertSuccessful();
    $this->artisan('reservations:expire')->expectsOutput('Expired 0 reservation(s).')->assertSuccessful();

    expect($overdue->fresh()->status)->toBe(ReservationStatus::Expired)
        ->and($overdue->fresh()->expired_at)->not->toBeNull()
        ->and($withinDeadline->fresh()->status)->toBe(ReservationStatus::Reserved)
        ->and($sentInTime->fresh()->status)->toBe(ReservationStatus::Reserved)
        ->and($confirmed->fresh()->status)->toBe(ReservationStatus::Confirmed)
        ->and($pending->fresh()->status)->toBe(ReservationStatus::Pending)
        ->and($overdue->unit->fresh()->hasRoomForAnotherTenant())->toBeTrue();

    Notification::assertSentOnDemandTimes(ReservationExpired::class, 1);
    Notification::assertSentOnDemand(ReservationExpired::class, fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === $overdue->email);
});

test('the expiry job runs every hour', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event) => str_contains((string) $event->command, 'reservations:expire'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 * * * *');
});

test('pruning also deletes the ID of reservations expired over 30 days ago', function () {
    Storage::fake(config('filesystems.sensitive_disk'));
    $disk = Storage::disk(config('filesystems.sensitive_disk'));

    $old = Reservation::factory()->status(ReservationStatus::Expired)->create(['expired_at' => now()->subDays(31)]);
    $recent = Reservation::factory()->status(ReservationStatus::Expired)->create(['expired_at' => now()->subDays(5)]);
    $disk->put($old->valid_id_path, 'id');
    $disk->put($recent->valid_id_path, 'id');

    $this->artisan('reservations:prune-files')->assertSuccessful();

    $disk->assertMissing($old->valid_id_path);
    $disk->assertExists($recent->valid_id_path);
    expect($old->fresh()->hasFiles())->toBeFalse()
        ->and($recent->fresh()->hasFiles())->toBeTrue();
});
