<?php

use App\Enums\LeaseStatus;
use App\Enums\ListingStatus;
use App\Enums\PropertyType;
use App\Enums\TeamRole;
use App\Enums\UnitStatus;
use App\Models\Lease;
use App\Models\ListingPhoto;
use App\Models\PaymentChannel;
use App\Models\Property;
use App\Models\Team;
use App\Models\Unit;
use App\Models\UnitListing;
use App\Models\User;

/**
 * @param  array<string, mixed>  $unit
 * @param  array<string, mixed>  $property
 */
function publicListing(string $state = 'approved', array $unit = [], array $property = [], ?Team $team = null): UnitListing
{
    $unit = Unit::factory()
        ->for(Property::factory()->for($team ?? Team::factory()->create())->create($property))
        ->create(array_merge(['status' => UnitStatus::Vacant, 'price' => 5000], $unit));

    $factory = UnitListing::factory()->for($unit);

    $listing = ($state === 'draft' ? $factory : $factory->{$state}())->create();
    ListingPhoto::factory()->for($listing, 'listing')->create();

    return $listing;
}

test('guests can browse approved listings with room', function () {
    $listing = publicListing(property: ['name' => 'Sunrise Dorm', 'city' => 'Lipa']);

    $this->get(route('listings.index'))
        ->assertOk()
        ->assertSee('Sunrise Dorm')
        ->assertSee('Lipa')
        ->assertSee('5,000.00')
        ->assertSee('1 slot available')
        ->assertSee(route('listings.show', $listing));
});

test('listings that are not approved are hidden from browse and 404 on detail', function (string $state) {
    $listing = publicListing($state, property: ['name' => 'Hidden Residences']);

    $this->get(route('listings.index'))->assertOk()->assertDontSee('Hidden Residences');
    $this->get(route('listings.show', $listing))->assertNotFound();
})->with(['draft', 'pendingReview', 'rejected']);

test('an unlisted listing is hidden', function () {
    $listing = publicListing('approved', property: ['name' => 'Taken Down Suites']);
    $listing->forceFill(['status' => ListingStatus::Unlisted])->save();

    $this->get(route('listings.index'))->assertDontSee('Taken Down Suites');
    $this->get(route('listings.show', $listing))->assertNotFound();
});

test('a full unit is hidden', function () {
    $listing = publicListing(unit: ['status' => UnitStatus::Occupied], property: ['name' => 'Full House']);

    $this->get(route('listings.index'))->assertDontSee('Full House');
    $this->get(route('listings.show', $listing))->assertNotFound();
});

test('a shared unit shows until it reaches its tenant limit', function () {
    $listing = publicListing(
        unit: ['status' => UnitStatus::Occupied, 'allows_multiple_tenants' => true, 'tenant_limit' => 3],
        property: ['name' => 'Shared Dorm'],
    );
    Lease::factory()->count(2)->for($listing->unit)->create(['status' => LeaseStatus::Active]);

    $this->get(route('listings.index'))->assertSee('Shared Dorm')->assertSee('1 slot available');

    Lease::factory()->for($listing->unit)->create(['status' => LeaseStatus::Active]);

    $this->get(route('listings.index'))->assertDontSee('Shared Dorm');
    $this->get(route('listings.show', $listing))->assertNotFound();
});

test('ended leases do not count against a shared unit', function () {
    $listing = publicListing(
        unit: ['status' => UnitStatus::Occupied, 'allows_multiple_tenants' => true, 'tenant_limit' => 2],
        property: ['name' => 'Shared Dorm'],
    );
    Lease::factory()->count(2)->for($listing->unit)->create(['status' => LeaseStatus::Ended]);

    $this->get(route('listings.index'))->assertSee('Shared Dorm')->assertSee('2 slots available');
});

test('filters narrow the results', function () {
    publicListing(property: ['name' => 'Cheap Lipa Dorm', 'city' => 'Lipa', 'province' => 'Batangas', 'type' => PropertyType::Dormitory], unit: ['price' => 3000]);
    publicListing(property: ['name' => 'Pricey Makati Condo', 'city' => 'Makati', 'province' => 'Metro Manila', 'type' => PropertyType::Condominium], unit: ['price' => 20000]);

    $this->get(route('listings.index', ['q' => 'lipa']))
        ->assertSee('Cheap Lipa Dorm')->assertDontSee('Pricey Makati Condo');

    $this->get(route('listings.index', ['q' => 'metro manila']))
        ->assertSee('Pricey Makati Condo')->assertDontSee('Cheap Lipa Dorm');

    $this->get(route('listings.index', ['type' => 'condominium']))
        ->assertSee('Pricey Makati Condo')->assertDontSee('Cheap Lipa Dorm');

    $this->get(route('listings.index', ['max_price' => 5000]))
        ->assertSee('Cheap Lipa Dorm')->assertDontSee('Pricey Makati Condo');

    $this->get(route('listings.index', ['q' => 'lipa', 'type' => 'condominium']))
        ->assertDontSee('Cheap Lipa Dorm')->assertDontSee('Pricey Makati Condo');
});

test('search treats wildcards literally', function () {
    publicListing(property: ['name' => 'Some Place', 'city' => 'Lipa']);

    $this->get(route('listings.index', ['q' => '%']))->assertDontSee('Some Place');
});

test('invalid filters are rejected', function () {
    $this->get(route('listings.index', ['type' => 'castle']))->assertSessionHasErrors('type');
    $this->get(route('listings.index', ['max_price' => 'abc']))->assertSessionHasErrors('max_price');
});

test('browse is paginated at twelve per page', function () {
    foreach (range(1, 13) as $i) {
        publicListing(property: ['name' => "Place {$i}"]);
    }

    $this->get(route('listings.index'))->assertOk()->assertViewHas('listings', fn ($listings) => $listings->count() === 12 && $listings->total() === 13);
    $this->get(route('listings.index', ['page' => 2]))->assertViewHas('listings', fn ($listings) => $listings->count() === 1);
});

test('the detail page shows public information', function () {
    $team = Team::factory()->create();
    PaymentChannel::factory()->for($team)->create();
    PaymentChannel::factory()->for($team)->bank()->create();
    PaymentChannel::factory()->for($team)->inactive()->create(['account_name' => 'Hidden Inactive']);
    $listing = publicListing(team: $team, property: ['name' => 'Sunrise Dorm', 'address_line' => '12 Rizal St', 'map_url' => 'https://maps.example.com/abc']);
    $listing->forceFill(['contact_name' => 'Maria Santos', 'contact_phone' => '09171234567', 'contact_email' => 'maria@example.com', 'downpayment_amount' => 2500])->save();

    $this->get(route('listings.show', $listing))
        ->assertOk()
        ->assertSee('Sunrise Dorm')
        ->assertSee('12 Rizal St')
        ->assertSee('https://maps.example.com/abc')
        ->assertSee('2,500.00')
        ->assertSee('GCash, Bank Transfer')
        ->assertSee('Maria Santos')
        ->assertSee('09171234567')
        ->assertSee('maria@example.com')
        ->assertDontSee('Hidden Inactive');
});

test('the detail page never exposes tenants or channel account details', function () {
    $team = Team::factory()->create();
    PaymentChannel::factory()->for($team)->create(['account_name' => 'Secret Account Holder', 'account_number' => '09998887777']);
    $listing = publicListing(team: $team, unit: ['status' => UnitStatus::Occupied, 'allows_multiple_tenants' => true, 'tenant_limit' => 3]);

    $tenant = User::factory()->create(['name' => 'Juana Tenant', 'email' => 'juana@tenant.test']);
    $team->members()->attach($tenant, ['role' => TeamRole::Tenant]);
    Lease::factory()->for($listing->unit)->for($tenant, 'tenant')->create(['status' => LeaseStatus::Active]);

    $this->get(route('listings.show', $listing))
        ->assertOk()
        ->assertDontSee('Juana Tenant')
        ->assertDontSee('juana@tenant.test')
        ->assertDontSee('Secret Account Holder')
        ->assertDontSee('09998887777');
});

test('a listing without an active channel says it is not accepting reservations', function () {
    $listing = publicListing();

    $this->get(route('listings.show', $listing))->assertOk()->assertSee('Not accepting online reservations right now');
});

test('a missing listing is a 404', function () {
    $this->get(route('listings.show', 999999))->assertNotFound();
});

test('the landing page has both entry points', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Find an apartment or dorm')
        ->assertSee(route('listings.index'))
        ->assertSee('I&#039;m a landlord', false)
        ->assertSee(route('register'));
});

test('the public pages link to log in for guests and the dashboard for landlords', function () {
    $this->get(route('listings.index'))->assertSee(route('login'));

    $landlord = User::factory()->create();

    $this->actingAs($landlord)->get(route('listings.index'))->assertSee('Dashboard');
});

test('a super admin without a team can view the public pages', function () {
    $admin = User::factory()->create();
    $admin->is_super_admin = true;
    $admin->save();
    $admin->forceFill(['current_team_id' => null])->save();

    $this->actingAs($admin)->get(route('home'))->assertOk()->assertSee(route('admin.dashboard'));
});
