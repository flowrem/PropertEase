<?php

use App\Actions\Units\StoreUnitPhoto;
use App\Enums\TeamRole;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('media');
});

test('a large photo is stored as a small webp on the public media disk', function () {
    $unit = Unit::factory()->create();

    app(StoreUnitPhoto::class)->handle($unit, UploadedFile::fake()->image('room.jpg', 3000, 2000));

    $path = $unit->fresh()->photo_path;
    Storage::disk('media')->assertExists($path);

    [$width, $height, $type] = getimagesizefromstring(Storage::disk('media')->get($path));

    expect($path)->toStartWith("units/{$unit->id}/")->toEndWith('.webp')
        ->and($type)->toBe(IMAGETYPE_WEBP)
        ->and($width)->toBe(StoreUnitPhoto::MAX_DIMENSION)
        ->and($height)->toBe(640)
        ->and($unit->fresh()->photoUrl())->toContain($path);
});

test('a photo smaller than the limit keeps its size', function () {
    $unit = Unit::factory()->create();

    app(StoreUnitPhoto::class)->handle($unit, UploadedFile::fake()->image('room.png', 400, 300));

    [$width, $height] = getimagesizefromstring(Storage::disk('media')->get($unit->fresh()->photo_path));

    expect([$width, $height])->toBe([400, 300]);
});

test('a new photo replaces the old file, and removing it falls back to no photo', function () {
    $unit = Unit::factory()->create();
    $action = app(StoreUnitPhoto::class);

    $action->handle($unit, UploadedFile::fake()->image('first.jpg'));
    $firstPath = $unit->fresh()->photo_path;

    $action->handle($unit->fresh(), UploadedFile::fake()->image('second.jpg'));
    $secondPath = $unit->fresh()->photo_path;

    Storage::disk('media')->assertMissing($firstPath);
    Storage::disk('media')->assertExists($secondPath);

    $action->remove($unit->fresh());

    Storage::disk('media')->assertMissing($secondPath);
    expect($unit->fresh()->photo_path)->toBeNull()
        ->and($unit->fresh()->photoUrl())->toBeNull();
});

test('a file that is not really an image is refused', function () {
    $unit = Unit::factory()->create();

    expect(fn () => app(StoreUnitPhoto::class)->handle($unit, UploadedFile::fake()->createWithContent('room.jpg', 'not an image')))
        ->toThrow(ValidationException::class);

    expect($unit->fresh()->photo_path)->toBeNull();
});

test('a landlord opens a unit page from its address and sees the placeholder until a photo is added', function () {
    $landlord = User::factory()->create();
    $unit = Unit::factory()->for(Property::factory()->for($landlord->currentTeam))->create(['unit_number' => '101', 'price' => 5000]);

    $this->actingAs($landlord)
        ->get(route('units.show', ['unit' => $unit]))
        ->assertOk()
        ->assertSee('Unit 101')
        ->assertSee('5,000.00')
        ->assertSee('No photo of unit 101 yet');
});

test('tenants cannot open a unit page, and another team\'s unit is not found', function () {
    $landlord = User::factory()->create();
    $unit = Unit::factory()->for(Property::factory()->for($landlord->currentTeam))->create();

    $tenant = User::factory()->create();
    $landlord->currentTeam->members()->attach($tenant, ['role' => TeamRole::Tenant]);
    $tenant->switchTeam($landlord->currentTeam);

    $this->actingAs($tenant)->get(route('units.show', ['unit' => $unit]))->assertForbidden();

    $otherLandlord = User::factory()->create();

    expect(fn () => Livewire::actingAs($otherLandlord)->test('pages::landlord.unit', ['unit' => $unit->id]))
        ->toThrow(ModelNotFoundException::class);
});

test('a landlord adds and removes a unit photo from the unit page', function () {
    $landlord = User::factory()->create();
    $unit = Unit::factory()->for(Property::factory()->for($landlord->currentTeam))->create();

    $component = Livewire::actingAs($landlord)
        ->test('pages::landlord.unit', ['unit' => $unit->id])
        ->set('photo', UploadedFile::fake()->image('room.jpg', 1200, 900))
        ->assertHasNoErrors();

    $path = $unit->fresh()->photo_path;
    Storage::disk('media')->assertExists($path);
    $component->assertSeeHtml($unit->fresh()->photoUrl());

    $component->call('removePhoto');

    Storage::disk('media')->assertMissing($path);
    expect($unit->fresh()->photo_path)->toBeNull();
});

test('a unit photo must be an image of at most 5 MB', function (UploadedFile $file) {
    $landlord = User::factory()->create();
    $unit = Unit::factory()->for(Property::factory()->for($landlord->currentTeam))->create();

    Livewire::actingAs($landlord)
        ->test('pages::landlord.unit', ['unit' => $unit->id])
        ->set('photo', $file)
        ->assertHasErrors(['photo']);

    expect($unit->fresh()->photo_path)->toBeNull();
})->with([
    'a pdf' => fn () => UploadedFile::fake()->create('plan.pdf', 100, 'application/pdf'),
    'over 5 MB' => fn () => UploadedFile::fake()->image('huge.jpg')->size(5121),
]);

test('the details tab opens from its address, and deleting the unit there removes its photo', function () {
    $landlord = User::factory()->create();
    $unit = Unit::factory()->for(Property::factory()->for($landlord->currentTeam))->create(['unit_number' => '101']);
    app(StoreUnitPhoto::class)->handle($unit, UploadedFile::fake()->image('room.jpg'));
    $path = $unit->fresh()->photo_path;

    $this->actingAs($landlord)
        ->get(route('units.edit', ['unit' => $unit]))
        ->assertOk()
        ->assertSee('Save unit');

    Livewire::actingAs($landlord)
        ->test('pages::landlord.unit-edit', ['unit' => $unit->id])
        ->call('confirmDeleteUnit')
        ->call('deleteUnit');

    expect(Unit::find($unit->id))->toBeNull();
    Storage::disk('media')->assertMissing($path);
});
