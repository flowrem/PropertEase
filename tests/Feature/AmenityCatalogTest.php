<?php

use App\Enums\AmenityCategory;
use App\Models\Amenity;
use App\Models\Team;

test('the platform defaults are seeded, with only beds sleeping anyone', function () {
    $defaults = Amenity::query()->whereNull('team_id')->pluck('sleeps', 'name');

    expect($defaults)->toHaveKeys(['Single bed', 'Double deck', 'Double bed', 'Television', 'Gas stove', 'Wi-Fi'])
        ->and($defaults['Single bed'])->toBe(1)
        ->and($defaults['Double deck'])->toBe(2)
        ->and($defaults['Double bed'])->toBe(2)
        ->and($defaults->except(['Single bed', 'Double deck', 'Double bed'])->unique()->values()->all())->toBe([0]);
});

test('a team sees the platform defaults and its own amenities, never another team\'s', function () {
    $team = Team::factory()->create();
    $own = Amenity::factory()->for($team)->create(['name' => 'Rooftop access']);
    $otherTeams = Amenity::factory()->create(['name' => 'Pool access']);

    $available = Amenity::availableTo($team)->pluck('id');

    expect($available)->toContain($own->id)
        ->toContain(Amenity::query()->whereNull('team_id')->where('name', 'Wi-Fi')->value('id'))
        ->not->toContain($otherTeams->id);
});

test('a custom amenity can never be made to sleep anyone', function () {
    $amenity = Amenity::create([
        'team_id' => Team::factory()->create()->id,
        'name' => 'Giant bed',
        'category' => AmenityCategory::Furniture,
        'sleeps' => 10,
    ]);

    expect($amenity->fresh()->sleeps)->toBe(0);
});
