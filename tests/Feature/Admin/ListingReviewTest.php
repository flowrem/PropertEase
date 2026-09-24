<?php

use App\Enums\ListingStatus;
use App\Enums\ReservationStatus;
use App\Enums\TeamRole;
use App\Models\ListingPhoto;
use App\Models\PaymentChannel;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Team;
use App\Models\Unit;
use App\Models\UnitListing;
use App\Models\User;
use App\Notifications\ListingReviewed;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

function superAdmin(): User
{
    $admin = User::factory()->create();
    $admin->is_super_admin = true;
    $admin->save();

    return $admin;
}

function pendingListingFor(User $landlord): UnitListing
{
    $unit = Unit::factory()->for(Property::factory()->for($landlord->currentTeam))->create();
    $listing = UnitListing::factory()->for($unit)->pendingReview()->create();
    ListingPhoto::factory()->for($listing, 'listing')->create();

    return $listing;
}

test('guests and landlords cannot open the review queue', function () {
    $landlord = User::factory()->create();

    $this->get(route('admin.listings'))->assertRedirect(route('login'));
    $this->actingAs($landlord)->get(route('admin.listings'))->assertForbidden();
});

test('a super admin sees pending listings oldest first and nothing else', function () {
    $landlord = User::factory()->create();
    $newer = pendingListingFor($landlord);
    $newer->forceFill(['title' => 'Newer listing', 'submitted_at' => now()])->save();
    $older = pendingListingFor($landlord);
    $older->forceFill(['title' => 'Older listing', 'submitted_at' => now()->subDays(2)])->save();
    $draft = UnitListing::factory()->create(['title' => 'Draft listing']);

    $this->actingAs(superAdmin());

    Livewire::test('pages::admin.listings')
        ->assertSeeInOrder(['Older listing', 'Newer listing'])
        ->assertDontSee('Draft listing');
});

test('the detail view shows the team\'s active payment channels only', function () {
    $landlord = User::factory()->create();
    $listing = pendingListingFor($landlord);
    PaymentChannel::factory()->for($landlord->currentTeam)->create(['account_name' => 'Active Account']);
    PaymentChannel::factory()->for($landlord->currentTeam)->inactive()->create(['account_name' => 'Inactive Account']);
    PaymentChannel::factory()->create(['account_name' => 'Other Team Account']);

    $this->actingAs(superAdmin());

    Livewire::test('pages::admin.listings')
        ->call('select', $listing->id)
        ->assertSee('Active Account')
        ->assertDontSee('Inactive Account')
        ->assertDontSee('Other Team Account');
});

test('approving records the reviewer and notifies the landlord and managers', function () {
    Notification::fake();

    $landlord = User::factory()->create();
    $manager = User::factory()->create();
    $staff = User::factory()->create();
    $landlord->currentTeam->members()->attach($manager, ['role' => TeamRole::Admin]);
    $landlord->currentTeam->members()->attach($staff, ['role' => TeamRole::Member]);
    $listing = pendingListingFor($landlord);
    $admin = superAdmin();

    $this->actingAs($admin);

    Livewire::test('pages::admin.listings')->call('approve', $listing->id);

    $listing->refresh();

    expect($listing->status)->toBe(ListingStatus::Approved)
        ->and($listing->reviewed_by)->toBe($admin->id)
        ->and($listing->reviewed_at)->not->toBeNull();

    Notification::assertSentTo([$landlord, $manager], ListingReviewed::class);
    Notification::assertNotSentTo($staff, ListingReviewed::class);
});

test('rejecting requires a reason and stores it', function () {
    $landlord = User::factory()->create();
    $listing = pendingListingFor($landlord);

    $this->actingAs(superAdmin());

    Livewire::test('pages::admin.listings')
        ->call('select', $listing->id)
        ->call('reject', $listing->id)
        ->assertHasErrors('rejection_reason');

    expect($listing->refresh()->status)->toBe(ListingStatus::PendingReview);

    Livewire::test('pages::admin.listings')
        ->call('select', $listing->id)
        ->set('rejection_reason', 'The photos do not show the unit.')
        ->call('reject', $listing->id)
        ->assertHasNoErrors();

    expect($listing->refresh()->status)->toBe(ListingStatus::Rejected)
        ->and($listing->rejection_reason)->toBe('The photos do not show the unit.');
});

test('only a super admin can approve or reject', function () {
    $landlord = User::factory()->create();
    $listing = pendingListingFor($landlord);

    $this->actingAs($landlord);

    Livewire::test('pages::admin.listings')->call('approve', $listing->id)->assertForbidden();
    Livewire::test('pages::admin.listings')
        ->set('rejection_reason', 'Nope')
        ->call('reject', $listing->id)
        ->assertForbidden();

    expect($listing->refresh()->status)->toBe(ListingStatus::PendingReview);
});

test('a listing that is no longer pending cannot be reviewed again', function () {
    Notification::fake();

    $landlord = User::factory()->create();
    $listing = pendingListingFor($landlord);
    $listing->forceFill(['status' => ListingStatus::Approved])->save();

    $this->actingAs(superAdmin());

    Livewire::test('pages::admin.listings')
        ->set('rejection_reason', 'Too late')
        ->call('reject', $listing->id);

    expect($listing->refresh()->status)->toBe(ListingStatus::Approved);
    Notification::assertNothingSent();
});

test('the dashboard shows listing counts by status', function () {
    UnitListing::factory()->count(2)->pendingReview()->create();
    UnitListing::factory()->approved()->create();

    $this->actingAs(superAdmin());

    $component = Livewire::test('pages::admin.dashboard');

    expect($component->instance()->listingCounts)->toMatchArray([
        ListingStatus::PendingReview->value => 2,
        ListingStatus::Approved->value => 1,
    ]);
});

test('the dashboard shows landlord review counts and platform totals', function () {
    Team::factory()->awaitingApproval()->count(2)->create();
    Team::factory()->rejectedByAdmin()->create();
    Team::factory()->create();
    Reservation::factory()->status(ReservationStatus::Approved)->create();

    $this->actingAs(superAdmin());

    $component = Livewire::test('pages::admin.dashboard')->assertSee('Dashboard');

    expect($component->instance()->landlords)->toMatchArray(['awaiting' => 2, 'rejected' => 1])
        ->and($component->instance()->platform['heldReservations'])->toBe(1);
});

test('a Super Admin can open every admin page over HTTP', function (string $routeName) {
    $admin = User::factory()->create();
    $admin->forceFill(['is_super_admin' => true])->save();

    $this->actingAs($admin)->get(route($routeName))->assertOk();
})->with(['admin.dashboard', 'admin.landlords', 'admin.listings']);
