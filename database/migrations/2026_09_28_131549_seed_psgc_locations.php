<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fill the PSGC tables from database/data/psgc.json, so production gets the
 * list on deploy. Written with the query builder, not the models, so later
 * model changes can't break it.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $data = json_decode((string) file_get_contents(database_path('data/psgc.json')), true, flags: JSON_THROW_ON_ERROR);

        DB::table('regions')->insert(array_map(
            fn (array $region): array => ['code' => $region[0], 'name' => $region[1]],
            $data['regions'],
        ));

        DB::table('provinces')->insert(array_map(
            fn (array $province): array => ['code' => $province[0], 'name' => $province[1], 'region_code' => $province[2]],
            $data['provinces'],
        ));

        foreach (array_chunk($data['cities'], 250) as $chunk) {
            DB::table('cities')->insert(array_map(
                fn (array $city): array => [
                    'code' => $city[0],
                    'name' => $city[1],
                    'province_code' => $city[2],
                    'region_code' => $city[3],
                    'is_city' => $city[4],
                ],
                $chunk,
            ));
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('cities')->delete();
        DB::table('provinces')->delete();
        DB::table('regions')->delete();
    }
};
