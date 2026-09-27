<?php

use App\Enums\ConcernCategory;
use App\Enums\LeaseStatus;
use App\Enums\TeamRole;
use App\Models\Concern;
use App\Models\Lease;
use App\Models\Property;
use App\Models\Team;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\TenantReportSubmitted;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * A maintenance report on the given team's unit.
 */
function reportOn(Team $team): Concern
{
    $unit = Unit::factory()->for(Property::factory()->for($team))->create();

    return Concern::factory()
        ->for(Lease::factory()->for($unit)->state(['status' => LeaseStatus::Active]))
        ->create(['category' => ConcernCategory::Maintenance, 'title' => 'Leaking faucet']);
}

/**
 * A landlord with one notification about their own team and one about
 * another team they also belong to.
 */
function landlordWithNotifications(): User
{
    $landlord = User::factory()->create();
    $otherTeam = Team::factory()->create();
    $otherTeam->members()->attach($landlord, ['role' => TeamRole::Admin]);

    $landlord->notify(new TenantReportSubmitted(reportOn($landlord->currentTeam)));
    $landlord->notify(new TenantReportSubmitted(reportOn($otherTeam)));
    $landlord->switchTeam($landlord->currentTeam);

    return $landlord;
}

test('the bell counts only unread notifications about the current team', function () {
    $landlord = landlordWithNotifications();
    Property::factory()->for($landlord->currentTeam)->create();

    $this->actingAs($landlord)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('1 unread notification');
});

test('staff and tenants get the bell too', function (TeamRole $role) {
    $landlord = User::factory()->create();
    $member = User::factory()->create();
    $landlord->currentTeam->members()->attach($member, ['role' => $role]);
    $member->switchTeam($landlord->currentTeam);
    Property::factory()->for($landlord->currentTeam)->create();

    $this->actingAs($member)->get(route('dashboard'))->assertOk()->assertSee(route('notifications'), false);
})->with([
    'staff' => TeamRole::Member,
    'tenant' => TeamRole::Tenant,
]);

test('the notifications page lists only the current team\'s notifications', function () {
    $landlord = landlordWithNotifications();

    Livewire::actingAs($landlord)->test('pages::notifications')
        ->assertSee('reported &quot;Leaking faucet&quot;', false)
        ->assertCount('notifications', 1);
});

test('opening a notification marks it read and goes to what it is about', function () {
    $landlord = landlordWithNotifications();
    $notification = $landlord->notificationsForTeam($landlord->currentTeam)->sole();

    Livewire::actingAs($landlord)->test('pages::notifications')
        ->call('open', $notification->id)
        ->assertRedirect(route('landlord.maintenance', ['report' => $notification->data['concern_id']]));

    expect($notification->fresh()->read_at)->not->toBeNull();
});

test('marking all as read leaves other teams\' notifications unread', function () {
    $landlord = landlordWithNotifications();

    Livewire::actingAs($landlord)->test('pages::notifications')->call('markAllRead');

    expect($landlord->unreadNotifications()->count())->toBe(1)
        ->and($landlord->unreadNotificationCountFor($landlord->currentTeam))->toBe(0);
});

test('a single notification can be marked read', function () {
    $landlord = landlordWithNotifications();
    $notification = $landlord->notificationsForTeam($landlord->currentTeam)->sole();

    Livewire::actingAs($landlord)->test('pages::notifications')->call('markRead', $notification->id);

    expect($notification->fresh()->read_at)->not->toBeNull();
});

test('someone else\'s notification cannot be opened', function () {
    $landlord = landlordWithNotifications();
    $notification = $landlord->notifications()->first();
    $intruder = User::factory()->create();

    Livewire::actingAs($intruder)->test('pages::notifications')
        ->call('open', $notification->id)
        ->assertNotFound();

    expect($notification->fresh()->read_at)->toBeNull();
});

test('notifications sent before they carried a team still show up', function () {
    $landlord = User::factory()->create();
    $landlord->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\ReservationSubmitted',
        'data' => ['message' => 'Juan dela Cruz submitted a reservation (R-ABC123).'],
    ]);

    Livewire::actingAs($landlord)->test('pages::notifications')
        ->assertSee('Juan dela Cruz submitted a reservation');
});
