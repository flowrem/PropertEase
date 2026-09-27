<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed the platform's default amenities, which every landlord sees. Done in
 * a migration rather than a seeder so production gets them on deploy.
 * Beds carry how many people one of them sleeps, which decides a unit's
 * capacity. Rows are written with the query builder, not the Amenity model,
 * so later changes to the model can't break this migration.
 */
return new class extends Migration
{
    /**
     * @var array<int, array{0: string, 1: string, 2: int}>
     */
    private array $defaults = [
        ['Single bed', 'furniture', 1],
        ['Double deck', 'furniture', 2],
        ['Double bed', 'furniture', 2],
        ['Mattress', 'furniture', 0],
        ['Cabinet', 'furniture', 0],
        ['Wardrobe', 'furniture', 0],
        ['Study table', 'furniture', 0],
        ['Chair', 'furniture', 0],
        ['Dining table', 'furniture', 0],
        ['Sofa', 'furniture', 0],
        ['Curtains', 'furniture', 0],
        ['Electric fan', 'appliances', 0],
        ['Air conditioner', 'appliances', 0],
        ['Refrigerator', 'appliances', 0],
        ['Television', 'appliances', 0],
        ['Rice cooker', 'appliances', 0],
        ['Gas stove', 'appliances', 0],
        ['Electric stove', 'appliances', 0],
        ['Induction cooker', 'appliances', 0],
        ['Microwave', 'appliances', 0],
        ['Electric kettle', 'appliances', 0],
        ['Washing machine', 'appliances', 0],
        ['Flat iron', 'appliances', 0],
        ['Wi-Fi', 'utilities', 0],
        ['Water included', 'utilities', 0],
        ['Electricity included', 'utilities', 0],
        ['Separate electric meter', 'utilities', 0],
        ['Separate water meter', 'utilities', 0],
        ['Water heater', 'bathroom', 0],
        ['Towel', 'bathroom', 0],
        ['Bidet', 'bathroom', 0],
        ['CCTV', 'safety', 0],
        ['Fire extinguisher', 'safety', 0],
        ['Smoke detector', 'safety', 0],
        ['Door lock with key', 'safety', 0],
        ['Parking', 'other', 0],
        ['Laundry area', 'other', 0],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $existing = DB::table('amenities')->whereNull('team_id')->pluck('name')->all();

        DB::table('amenities')->insert(
            collect($this->defaults)
                ->reject(fn (array $amenity): bool => in_array($amenity[0], $existing, true))
                ->map(fn (array $amenity): array => [
                    'team_id' => null,
                    'name' => $amenity[0],
                    'category' => $amenity[1],
                    'sleeps' => $amenity[2],
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
                ->values()
                ->all(),
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('amenities')
            ->whereNull('team_id')
            ->whereIn('name', array_column($this->defaults, 0))
            ->delete();
    }
};
