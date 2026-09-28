<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Give properties typed before the dropdowns their PSGC codes when the typed
 * city and province point at exactly one city or municipality, so they show
 * up in the Region and City filters. "Lipa" matches "City of Lipa", and a
 * Metro Manila city matches with "Metro Manila" or "NCR" as the province.
 * Anything unclear is left as typed until the landlord edits the property.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $provinceNames = DB::table('provinces')->pluck('name', 'code')->map(fn (string $name): string => $this->normalize($name));

        $citiesByName = DB::table('cities')
            ->get(['code', 'name', 'province_code', 'region_code'])
            ->map(fn (object $city): array => get_object_vars($city))
            ->groupBy(fn (array $city): string => $this->normalize($city['name']));

        $properties = DB::table('properties')->whereNull('city_code')->orderBy('id')->get(['id', 'city', 'province'])
            ->map(fn (object $property): array => get_object_vars($property));

        foreach ($properties as $property) {
            $typedProvince = $this->normalize((string) $property['province']);

            $matches = ($citiesByName[$this->normalize((string) $property['city'])] ?? collect())
                ->filter(fn (array $city): bool => $city['province_code'] !== null
                    ? $provinceNames[$city['province_code']] === $typedProvince
                    : in_array($typedProvince, ['metromanila', 'ncr', 'nationalcapitalregion', ''], true));

            if ($matches->count() !== 1) {
                continue;
            }

            $city = $matches->first();

            DB::table('properties')->where('id', $property['id'])->update([
                'region_code' => $city['region_code'],
                'province_code' => $city['province_code'],
                'city_code' => $city['code'],
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // The matched codes are indistinguishable from chosen ones, and the
        // typed names are untouched, so there is nothing to undo.
    }

    /**
     * A place name reduced to letters and digits, without "City of" or
     * "City", so the ways people type a city compare equal.
     */
    private function normalize(string $name): string
    {
        $name = Str::lower(Str::ascii($name));
        $name = (string) preg_replace('/^city of |^municipality of | city$/', '', trim($name));

        return (string) preg_replace('/[^a-z0-9]/', '', $name);
    }
};
