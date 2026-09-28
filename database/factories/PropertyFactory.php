<?php

namespace Database\Factories;

use App\Enums\PropertyType;
use App\Models\City;
use App\Models\Property;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Property>
 */
class PropertyFactory extends Factory
{
    /**
     * Define the model's default state: an address picked from the PSGC
     * list, as the property form makes them.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $city = City::query()->with(['province', 'region'])->inRandomOrder()->first();

        return [
            'team_id' => Team::factory(),
            'name' => fake()->company().' '.fake()->randomElement(['Residences', 'Suites', 'Place', 'Court']),
            'address_line' => fake()->streetAddress(),
            'region_code' => $city?->region_code,
            'province_code' => $city?->province_code,
            'city_code' => $city?->code,
            'city' => $city->name ?? fake()->city(),
            'province' => $city?->province->name ?? 'Metro Manila',
            'postal_code' => fake()->postcode(),
            'type' => fake()->randomElement(PropertyType::cases()),
        ];
    }

    /**
     * A property from before the dropdowns: a typed city and province only.
     */
    public function withTypedLocation(string $city = 'New Nelsfurt', string $province = 'Cebu'): static
    {
        return $this->state(fn () => [
            'region_code' => null,
            'province_code' => null,
            'city_code' => null,
            'city' => $city,
            'province' => $province,
        ]);
    }
}
