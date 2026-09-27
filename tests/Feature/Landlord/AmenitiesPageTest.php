<?php

use App\Enums\AmenityCategory;
use App\Enums\AmenityQuantityBasis;
use App\Enums\TeamRole;
use App\Models\Amenity;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

function amenitiesTeamMember(User $landlord, TeamRole $role): User
{
    $member = User::factory()->create();
    $landlord->currentTeam->members()->attach($member, ['role' => $role]);
    $member->switchTeam($landlord->currentTeam);

    return $member;
}

test('tenants cannot open the amenities page', function () {
    $landlord = User::factory()->create();
    $tenant = amenitiesTeamMember($landlord, TeamRole::Tenant);

    $this->actingAs($tenant)->get(route('amenities'))->assertForbidden();
});

test('a landlord sees the platform defaults with how many of each a unit can have', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('amenities'))
        ->assertOk()
        ->assertSee('Double deck')
        ->assertSee('sleeps 2, covers 1.7 m²')
        ->assertSee('Television')
        ->assertSee('1 per bedroom, plus 1 for the living area');
});

test('a landlord can add their own amenity', function () {
    $landlord = User::factory()->create();

    Livewire::actingAs($landlord)
        ->test('pages::landlord.amenities')
        ->set('name', '  Rooftop access ')
        ->set('category', AmenityCategory::Other->value)
        ->call('addAmenity')
        ->assertHasNoErrors();

    $amenity = $landlord->currentTeam->amenities()->sole();

    expect($amenity->name)->toBe('Rooftop access')
        ->and($amenity->category)->toBe(AmenityCategory::Other)
        ->and($amenity->sleeps)->toBe(0)
        ->and($amenity->fresh()->quantity_basis)->toBe(AmenityQuantityBasis::PerTenant);
});

test('an amenity cannot repeat a name already on the team\'s list, ignoring case', function (string $name) {
    $landlord = User::factory()->create();
    Amenity::factory()->for($landlord->currentTeam)->create(['name' => 'Rooftop access']);

    Livewire::actingAs($landlord)
        ->test('pages::landlord.amenities')
        ->set('name', $name)
        ->call('addAmenity')
        ->assertHasErrors(['name']);
})->with([
    'a platform default' => 'wi-fi',
    'the team\'s own' => 'ROOFTOP ACCESS',
]);

test('another team\'s amenity name does not block adding the same one', function () {
    $landlord = User::factory()->create();
    Amenity::factory()->create(['name' => 'Rooftop access']);

    Livewire::actingAs($landlord)
        ->test('pages::landlord.amenities')
        ->set('name', 'Rooftop access')
        ->call('addAmenity')
        ->assertHasNoErrors();
});

test('a landlord can deactivate and reactivate their own amenity', function () {
    $landlord = User::factory()->create();
    $amenity = Amenity::factory()->for($landlord->currentTeam)->create();

    $page = Livewire::actingAs($landlord)->test('pages::landlord.amenities');

    $page->call('toggleActive', $amenity->id);
    expect($amenity->fresh()->is_active)->toBeFalse();

    $page->call('toggleActive', $amenity->id);
    expect($amenity->fresh()->is_active)->toBeTrue();
});

test('staff can view amenities but not change them', function () {
    $landlord = User::factory()->create();
    $staff = amenitiesTeamMember($landlord, TeamRole::Member);
    $amenity = Amenity::factory()->for($landlord->currentTeam)->create();

    $this->actingAs($staff);

    Livewire::test('pages::landlord.amenities')
        ->assertSee($amenity->name)
        ->assertSee('You can view this list');
    Livewire::test('pages::landlord.amenities')->set('name', 'Rooftop access')->call('addAmenity')->assertForbidden();
    Livewire::test('pages::landlord.amenities')->call('toggleActive', $amenity->id)->assertForbidden();

    expect($amenity->fresh()->is_active)->toBeTrue()
        ->and($landlord->currentTeam->amenities()->count())->toBe(1);
});

test('a landlord cannot deactivate a platform default or another team\'s amenity', function (Amenity $target) {
    $landlord = User::factory()->create();

    expect(fn () => Livewire::actingAs($landlord)
        ->test('pages::landlord.amenities')
        ->call('toggleActive', $target->id))
        ->toThrow(ModelNotFoundException::class);

    expect($target->fresh()->is_active)->toBeTrue();
})->with([
    'a platform default' => fn () => Amenity::query()->whereNull('team_id')->where('name', 'Wi-Fi')->firstOrFail(),
    'another team\'s' => fn () => Amenity::factory()->create(),
]);
