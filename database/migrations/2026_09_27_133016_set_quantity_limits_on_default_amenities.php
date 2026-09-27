<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give each platform amenity a realistic quantity limit, so a unit can't be
 * saved with, say, 13 double decks in 24 m². Beds are limited by the floor
 * space they cover (standard mattress sizes: a single is about 0.9 × 1.9 m,
 * a double about 1.37 × 1.9 m, and a double deck covers the same floor as a
 * single); everything else by one thing about the unit. Written with the
 * query builder, not the Amenity model, so later model changes can't break it.
 */
return new class extends Migration
{
    /**
     * Name => [basis, how many per basis, footprint in m² for beds].
     *
     * @var array<string, array{0: string, 1: int, 2: float|null}>
     */
    private array $limits = [
        'Single bed' => ['floor_space', 1, 1.7],
        'Double deck' => ['floor_space', 1, 1.7],
        'Double bed' => ['floor_space', 1, 2.6],
        'Mattress' => ['per_tenant', 1, null],
        'Cabinet' => ['per_tenant', 1, null],
        'Wardrobe' => ['per_tenant', 1, null],
        'Study table' => ['per_tenant', 1, null],
        'Chair' => ['per_tenant', 2, null],
        'Dining table' => ['per_unit', 1, null],
        'Sofa' => ['per_unit', 1, null],
        'Curtains' => ['per_room', 2, null],
        'Electric fan' => ['per_tenant', 1, null],
        'Air conditioner' => ['per_room', 1, null],
        'Refrigerator' => ['per_unit', 1, null],
        'Television' => ['per_room', 1, null],
        'Rice cooker' => ['per_unit', 1, null],
        'Gas stove' => ['per_unit', 1, null],
        'Electric stove' => ['per_unit', 1, null],
        'Induction cooker' => ['per_unit', 1, null],
        'Microwave' => ['per_unit', 1, null],
        'Electric kettle' => ['per_unit', 1, null],
        'Washing machine' => ['per_unit', 1, null],
        'Flat iron' => ['per_unit', 1, null],
        'Wi-Fi' => ['single', 1, null],
        'Water included' => ['single', 1, null],
        'Electricity included' => ['single', 1, null],
        'Separate electric meter' => ['single', 1, null],
        'Separate water meter' => ['single', 1, null],
        'Water heater' => ['per_bathroom', 1, null],
        'Towel' => ['per_tenant', 2, null],
        'Bidet' => ['per_bathroom', 1, null],
        'CCTV' => ['single', 1, null],
        'Fire extinguisher' => ['per_unit', 2, null],
        'Smoke detector' => ['per_room', 1, null],
        'Door lock with key' => ['per_room', 1, null],
        'Parking' => ['single', 1, null],
        'Laundry area' => ['single', 1, null],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->limits as $name => [$basis, $per, $footprint]) {
            DB::table('amenities')
                ->whereNull('team_id')
                ->where('name', $name)
                ->update([
                    'quantity_basis' => $basis,
                    'quantity_per' => $per,
                    'footprint_sqm' => $footprint,
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('amenities')
            ->whereNull('team_id')
            ->whereIn('name', array_keys($this->limits))
            ->update(['quantity_basis' => 'per_tenant', 'quantity_per' => 1, 'footprint_sqm' => null]);
    }
};
