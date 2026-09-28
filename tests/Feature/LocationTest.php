<?php

use App\Enums\ListingStatus;
use App\Enums\UnitStatus;
use App\Livewire\Forms\LocationForm;
use App\Models\City;
use App\Models\ListingPhoto;
use App\Models\Property;
use App\Models\Province;
use App\Models\Region;
use App\Models\Unit;
use App\Models\UnitListing;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

function psgcCity(string $name, ?string $province = null): City
{
    return City::query()
        ->where('name', $name)
        ->when($province, fn ($query) => $query->whereHas('province', fn ($provinces) => $provinces->where('name', $province)))
        ->firstOrFail();
}

function visibleListingIn(City $city, string $unitNumber = '101'): UnitListing
{
    $property = Property::factory()->create([
        'region_code' => $city->region_code,
        'province_code' => $city->province_code,
        'city_code' => $city->code,
        'city' => $city->name,
    ]);
    $unit = Unit::factory()->for($property)->create(['status' => UnitStatus::Vacant, 'unit_number' => $unitNumber]);
    $listing = UnitListing::factory()->for($unit)->create(['status' => ListingStatus::Approved, 'title' => "Room in {$city->name}"]);
    ListingPhoto::factory()->for($listing, 'listing')->create();

    return $listing;
}

test('the seeded list is consistent: every city lies in its province\'s region, and Metro Manila has no provinces', function () {
    expect(Region::count())->toBe(18)
        ->and(Province::count())->toBe(82)
        ->and(City::count())->toBe(1642);

    $mismatched = DB::table('cities')
        ->join('provinces', 'provinces.code', '=', 'cities.province_code')
        ->whereColumn('provinces.region_code', '!=', 'cities.region_code')
        ->count();

    expect($mismatched)->toBe(0)
        ->and(psgcCity('City of Lipa', 'Batangas')->is_city)->toBeTrue()
        ->and(psgcCity('Quezon City')->province_code)->toBeNull()
        ->and(psgcCity('Quezon City')->region->isMetroManila())->toBeTrue();
});

test('each dropdown narrows to the one above it, with Metro Manila and cities under no province as their own choice', function () {
    $lipa = psgcCity('City of Lipa', 'Batangas');
    $quezonCity = psgcCity('Quezon City');
    $isabela = psgcCity('City of Isabela');
    $form = new LocationForm(Livewire::actingAs(User::factory()->create())->test('pages::landlord.setup')->instance(), 'location');

    $form->region_code = $lipa->region_code;

    expect($form->provinceOptions())->toHaveKey($lipa->province_code)
        ->not->toHaveKey(LocationForm::NO_PROVINCE);

    $form->province_code = $lipa->province_code;

    expect($form->cities()->pluck('code'))->toContain($lipa->code)
        ->and($form->cities()->pluck('province_code')->unique()->all())->toBe([$lipa->province_code]);

    $form->region_code = $quezonCity->region_code;

    expect($form->provinceOptions())->toBe([LocationForm::NO_PROVINCE => 'Metro Manila']);

    $form->region_code = $isabela->region_code;

    expect($form->provinceOptions())->toHaveKey(LocationForm::NO_PROVINCE, 'Not in a province');

    $form->province_code = LocationForm::NO_PROVINCE;

    expect($form->cities()->pluck('code')->all())->toContain($isabela->code);
});

test('changing the region clears the province and city', function () {
    $lipa = psgcCity('City of Lipa', 'Batangas');
    $cebu = psgcCity('City of Cebu');

    Livewire::actingAs(User::factory()->create())
        ->test('pages::landlord.setup')
        ->set('location.region_code', $lipa->region_code)
        ->set('location.province_code', $lipa->province_code)
        ->set('location.city_code', $lipa->code)
        ->set('location.region_code', $cebu->region_code)
        ->assertSet('location.province_code', '')
        ->assertSet('location.city_code', '');
});

test('a city from another province or region is refused, even in a crafted request', function () {
    $lipa = psgcCity('City of Lipa', 'Batangas');
    $cebu = psgcCity('City of Cebu');
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::landlord.setup')
        ->set('name', 'Sunrise Apartments')
        ->set('address_line', '123 Main St')
        ->set('postal_code', '4217')
        ->set('location.region_code', $lipa->region_code)
        ->set('location.province_code', $lipa->province_code)
        ->set('location.city_code', $cebu->code)
        ->call('createProperty')
        ->assertHasErrors(['location.city_code']);

    expect(Property::count())->toBe(0);
});

test('a property typed before the dropdowns shows its old address and gets codes once the landlord picks them', function () {
    $user = User::factory()->create();
    $property = Property::factory()->for($user->currentTeam)->withTypedLocation('New Nelsfurt', 'Cebu')->create();
    $lipa = psgcCity('City of Lipa', 'Batangas');

    Livewire::actingAs($user)
        ->test('pages::landlord.properties')
        ->call('startEditingProperty', $property->id)
        ->assertSee('This address was typed as "New Nelsfurt, Cebu".')
        ->assertSet('location.city_code', '')
        ->call('updateProperty')
        ->assertHasErrors(['location.region_code'])
        ->set('location.region_code', $lipa->region_code)
        ->set('location.province_code', $lipa->province_code)
        ->set('location.city_code', $lipa->code)
        ->call('updateProperty')
        ->assertHasNoErrors();

    expect($property->fresh())
        ->city_code->toBe($lipa->code)
        ->city->toBe('City of Lipa')
        ->province->toBe('Batangas');
});

test('the matching migration fills codes only when the typed city and province point at one place', function () {
    $lipa = psgcCity('City of Lipa', 'Batangas');
    $quezonCity = psgcCity('Quezon City');
    $typed = fn (string $city, string $province) => Property::factory()->withTypedLocation($city, $province)->create();

    $lipaCity = $typed('Lipa City', 'batangas');
    $metro = $typed('Quezon City', 'NCR');
    $ambiguous = $typed('San Jose', '');
    $unknown = $typed('New Nelsfurt', 'Cebu');

    (require database_path('migrations/2026_09_28_131618_match_existing_property_locations.php'))->up();

    expect($lipaCity->fresh()->city_code)->toBe($lipa->code)
        ->and($lipaCity->fresh()->province_code)->toBe($lipa->province_code)
        ->and($metro->fresh()->city_code)->toBe($quezonCity->code)
        ->and($ambiguous->fresh()->city_code)->toBeNull()
        ->and($unknown->fresh()->city_code)->toBeNull()
        ->and($unknown->fresh()->city)->toBe('New Nelsfurt');
});

test('guests filter listings by region and by a city that has listings', function () {
    $lipa = psgcCity('City of Lipa', 'Batangas');
    $batangasCity = psgcCity('Batangas City', 'Batangas');
    $cebu = psgcCity('City of Cebu');
    visibleListingIn($lipa);
    visibleListingIn($batangasCity);
    visibleListingIn($cebu);

    $this->get(route('listings.index', ['region' => $lipa->region_code]))
        ->assertOk()
        ->assertSee('Room in City of Lipa')
        ->assertSee('Room in Batangas City')
        ->assertDontSee('Room in City of Cebu')
        ->assertSee('<option value="'.$lipa->code.'"', false)
        ->assertDontSee('<option value="'.psgcCity('City of Tanauan', 'Batangas')->code.'"', false);

    $this->get(route('listings.index', ['region' => $lipa->region_code, 'city' => $lipa->code]))
        ->assertSee('Room in City of Lipa')
        ->assertDontSee('Room in Batangas City');

    $this->get(route('listings.index', ['region' => $cebu->region_code, 'city' => $lipa->code]))
        ->assertSee('Room in City of Cebu')
        ->assertDontSee('Room in City of Lipa');
});

test('an unknown region or city is refused by the browse filters', function (array $query) {
    $this->get(route('listings.index', $query))->assertSessionHasErrors(array_keys($query));
})->with([
    'region' => [['region' => '9999999999']],
    'city' => [['city' => 'nope']],
]);
