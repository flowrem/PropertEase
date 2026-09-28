<?php

use App\Actions\Units\StoreUnitPhoto;
use App\Models\Unit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

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
