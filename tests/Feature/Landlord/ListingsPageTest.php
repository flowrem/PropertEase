<?php

use App\Enums\ListingStatus;
use App\Enums\TeamRole;
use App\Models\ListingPhoto;
use App\Models\PaymentChannel;
use App\Models\Property;
use App\Models\Unit;
use App\Models\UnitListing;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('media');
});

function listingFor(User $landlord, string $state = 'draft'): UnitListing
{
    $unit = Unit::factory()->for(Property::factory()->for($landlord->currentTeam))->create();
    $factory = UnitListing::factory()->for($unit);

    return ($state === 'draft' ? $factory : $factory->{$state}())->create();
}

function listingWithPhoto(User $landlord, string $state = 'draft'): UnitListing
{
    $listing = listingFor($landlord, $state);
    ListingPhoto::factory()->for($listing, 'listing')->create();

    return $listing;
}

function memberOf(User $landlord, TeamRole $role): User
{
    $member = User::factory()->create();
    $landlord->currentTeam->members()->attach($member, ['role' => $role]);
    $member->switchTeam($landlord->currentTeam);

    return $member;
}

test('guests are redirected to the login page', function () {
    User::factory()->create();

    $this->get(route('listings'))->assertRedirect(route('login'));
});

test('tenants cannot open the listings page', function () {
    $landlord = User::factory()->create();

    $this->actingAs(memberOf($landlord, TeamRole::Tenant))->get(route('listings'))->assertForbidden();
});

test('a landlord can create a draft listing with photos', function () {
    $landlord = User::factory()->create();
    $unit = Unit::factory()->for(Property::factory()->for($landlord->currentTeam))->create();

    $this->actingAs($landlord);

    Livewire::test('pages::landlord.listings')
        ->call('openCreate')
        ->set('unit_id', $unit->id)
        ->set('title', 'Sunny studio near the university')
        ->set('description', 'Walking distance to campus.')
        ->set('contact_name', 'Maria Santos')
        ->set('contact_phone', '09171234567')
        ->set('contact_email', 'maria@example.com')
        ->set('downpayment_amount', '2500')
        ->set('newPhotos', [UploadedFile::fake()->image('front.jpg'), UploadedFile::fake()->image('room.png')])
        ->call('save')
        ->assertHasNoErrors();

    $listing = $unit->refresh()->listing;

    expect($listing->status)->toBe(ListingStatus::Draft)
        ->and($listing->photos)->toHaveCount(2)
        ->and($listing->photos->first()->path)->not->toContain('front');

    Storage::disk('media')->assertExists($listing->photos->first()->path);
});

test('required fields are validated', function () {
    $landlord = User::factory()->create();

    $this->actingAs($landlord);

    Livewire::test('pages::landlord.listings')
        ->call('openCreate')
        ->call('save')
        ->assertNotFound();

    $unit = Unit::factory()->for(Property::factory()->for($landlord->currentTeam))->create();

    Livewire::test('pages::landlord.listings')
        ->set('unit_id', $unit->id)
        ->call('save')
        ->assertHasErrors(['title', 'description', 'contact_name', 'contact_phone', 'contact_email']);
});

test('photo uploads are validated', function (UploadedFile $file) {
    $landlord = User::factory()->create();
    $unit = Unit::factory()->for(Property::factory()->for($landlord->currentTeam))->create();

    $this->actingAs($landlord);

    Livewire::test('pages::landlord.listings')
        ->set('unit_id', $unit->id)
        ->set('title', 'Title')
        ->set('description', 'Description')
        ->set('contact_name', 'Maria')
        ->set('contact_phone', '0917')
        ->set('contact_email', 'maria@example.com')
        ->set('newPhotos', [$file])
        ->call('save')
        ->assertHasErrors('newPhotos.0');

    expect(UnitListing::count())->toBe(0);
})->with([
    'a pdf' => fn () => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
    'over 4 MB' => fn () => UploadedFile::fake()->image('big.jpg')->size(5000),
]);

test('a listing cannot have more than eight photos', function () {
    $landlord = User::factory()->create();
    $unit = Unit::factory()->for(Property::factory()->for($landlord->currentTeam))->create();

    $this->actingAs($landlord);

    Livewire::test('pages::landlord.listings')
        ->set('unit_id', $unit->id)
        ->set('title', 'Title')
        ->set('description', 'Description')
        ->set('contact_name', 'Maria')
        ->set('contact_phone', '0917')
        ->set('contact_email', 'maria@example.com')
        ->set('newPhotos', array_map(fn ($i) => UploadedFile::fake()->image("p{$i}.jpg"), range(1, 9)))
        ->call('save')
        ->assertHasErrors('newPhotos');

    expect(UnitListing::count())->toBe(0);
});

test('photos can be removed and reordered', function () {
    $landlord = User::factory()->create();
    $listing = listingFor($landlord);
    [$first, $second, $third] = collect(range(0, 2))
        ->map(function (int $i) use ($listing) {
            Storage::disk('media')->put("listings/{$i}.jpg", 'x');

            return ListingPhoto::factory()->for($listing, 'listing')->create(['path' => "listings/{$i}.jpg", 'sort_order' => $i]);
        })->all();

    $this->actingAs($landlord);

    Livewire::test('pages::landlord.listings')
        ->call('openEdit', $listing->id)
        ->call('movePhoto', $third->id, -1)
        ->call('removePhoto', $first->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($listing->photos()->pluck('id')->all())->toBe([$third->id, $second->id]);
    Storage::disk('media')->assertMissing('listings/0.jpg');
});

test('submitting for review needs an active payment channel', function () {
    $landlord = User::factory()->create();
    $listing = listingWithPhoto($landlord);
    PaymentChannel::factory()->for($landlord->currentTeam)->inactive()->create();

    $this->actingAs($landlord);

    Livewire::test('pages::landlord.listings')->call('submitForReview', $listing->id);

    expect($listing->refresh()->status)->toBe(ListingStatus::Draft);
});

test('submitting for review needs at least one photo', function () {
    $landlord = User::factory()->create();
    $listing = listingFor($landlord);
    PaymentChannel::factory()->for($landlord->currentTeam)->create();

    $this->actingAs($landlord);

    Livewire::test('pages::landlord.listings')->call('submitForReview', $listing->id);

    expect($listing->refresh()->status)->toBe(ListingStatus::Draft);
});

test('a draft with a photo and an active channel can be submitted', function () {
    $landlord = User::factory()->create();
    $listing = listingWithPhoto($landlord);
    PaymentChannel::factory()->for($landlord->currentTeam)->create();

    $this->actingAs($landlord);

    Livewire::test('pages::landlord.listings')->call('submitForReview', $listing->id);

    $listing->refresh();

    expect($listing->status)->toBe(ListingStatus::PendingReview)
        ->and($listing->submitted_at)->not->toBeNull();
});

test('a rejected listing can be resubmitted and clears its reason', function () {
    $landlord = User::factory()->create();
    $listing = listingWithPhoto($landlord, 'rejected');
    PaymentChannel::factory()->for($landlord->currentTeam)->create();

    $this->actingAs($landlord);

    Livewire::test('pages::landlord.listings')->call('submitForReview', $listing->id);

    expect($listing->refresh()->status)->toBe(ListingStatus::PendingReview)
        ->and($listing->rejection_reason)->toBeNull();
});

test('editing an approved listing sends it back to review', function () {
    $landlord = User::factory()->create();
    $listing = listingWithPhoto($landlord, 'approved');

    $this->actingAs($landlord);

    Livewire::test('pages::landlord.listings')
        ->call('openEdit', $listing->id)
        ->assertSee('takes it off the public pages')
        ->set('title', 'A new title')
        ->call('save')
        ->assertHasNoErrors();

    expect($listing->refresh()->status)->toBe(ListingStatus::PendingReview)
        ->and($listing->title)->toBe('A new title');
});

test('editing a draft leaves it a draft', function () {
    $landlord = User::factory()->create();
    $listing = listingFor($landlord);

    $this->actingAs($landlord);

    Livewire::test('pages::landlord.listings')
        ->call('openEdit', $listing->id)
        ->set('title', 'Changed')
        ->call('save');

    expect($listing->refresh()->status)->toBe(ListingStatus::Draft);
});

test('an approved listing can be unlisted but a draft cannot', function () {
    $landlord = User::factory()->create();
    $approved = listingFor($landlord, 'approved');
    $draft = listingFor($landlord);

    $this->actingAs($landlord);

    Livewire::test('pages::landlord.listings')
        ->call('unlist', $approved->id)
        ->call('unlist', $draft->id);

    expect($approved->refresh()->status)->toBe(ListingStatus::Unlisted)
        ->and($draft->refresh()->status)->toBe(ListingStatus::Draft);
});

test('a manager can manage listings', function () {
    $landlord = User::factory()->create();
    $listing = listingWithPhoto($landlord);
    PaymentChannel::factory()->for($landlord->currentTeam)->create();

    $this->actingAs(memberOf($landlord, TeamRole::Admin));

    Livewire::test('pages::landlord.listings')->call('submitForReview', $listing->id);

    expect($listing->refresh()->status)->toBe(ListingStatus::PendingReview);
});

test('staff can view listings but not change them', function () {
    $landlord = User::factory()->create();
    $listing = listingWithPhoto($landlord, 'approved');
    $listing->update(['title' => 'Cozy corner unit']);

    $this->actingAs(memberOf($landlord, TeamRole::Member));

    Livewire::test('pages::landlord.listings')
        ->assertSee('Cozy corner unit')
        ->assertDontSee('New listing')
        ->assertDontSee('Unlist')
        ->assertDontSee('Edit');

    Livewire::test('pages::landlord.listings')->call('openCreate')->assertForbidden();
    Livewire::test('pages::landlord.listings')->call('openEdit', $listing->id)->assertForbidden();
    Livewire::test('pages::landlord.listings')->call('unlist', $listing->id)->assertForbidden();
    Livewire::test('pages::landlord.listings')->call('submitForReview', $listing->id)->assertForbidden();

    expect($listing->refresh()->status)->toBe(ListingStatus::Approved);
});

test('a landlord cannot touch another team\'s listing', function () {
    $landlord = User::factory()->create();
    $otherLandlord = User::factory()->create();
    $otherListing = listingWithPhoto($otherLandlord);
    PaymentChannel::factory()->for($landlord->currentTeam)->create();

    $this->actingAs($landlord);

    $page = Livewire::test('pages::landlord.listings');

    expect(fn () => $page->call('openEdit', $otherListing->id))->toThrow(ModelNotFoundException::class);
    expect(fn () => $page->call('submitForReview', $otherListing->id))->toThrow(ModelNotFoundException::class);
    expect(fn () => $page->call('unlist', $otherListing->id))->toThrow(ModelNotFoundException::class);
    expect($otherListing->refresh()->status)->toBe(ListingStatus::Draft);
});

test('a landlord cannot create a listing for another team\'s unit', function () {
    $landlord = User::factory()->create();
    $otherLandlord = User::factory()->create();
    $otherUnit = Unit::factory()->for(Property::factory()->for($otherLandlord->currentTeam))->create();

    $this->actingAs($landlord);

    Livewire::test('pages::landlord.listings')
        ->set('unit_id', $otherUnit->id)
        ->set('title', 'Title')
        ->set('description', 'Description')
        ->set('contact_name', 'Maria')
        ->set('contact_phone', '0917')
        ->set('contact_email', 'maria@example.com')
        ->call('save')
        ->assertNotFound();

    expect(UnitListing::count())->toBe(0);
});

test('a unit can only have one listing', function () {
    $landlord = User::factory()->create();
    $listing = listingFor($landlord);

    $this->actingAs($landlord);

    Livewire::test('pages::landlord.listings')
        ->set('unit_id', $listing->unit_id)
        ->set('title', 'Duplicate')
        ->set('description', 'Description')
        ->set('contact_name', 'Maria')
        ->set('contact_phone', '0917')
        ->set('contact_email', 'maria@example.com')
        ->call('save')
        ->assertNotFound();

    expect(UnitListing::count())->toBe(1);
});
