<?php

namespace Database\Factories;

use App\Models\ListingPhoto;
use App\Models\UnitListing;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ListingPhoto>
 */
class ListingPhotoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'unit_listing_id' => UnitListing::factory(),
            'path' => 'listings/'.fake()->uuid().'.jpg',
            'sort_order' => 0,
        ];
    }
}
