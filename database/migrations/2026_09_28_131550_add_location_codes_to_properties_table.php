<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A property's PSGC region, province and city. Nullable: properties made
 * before the dropdowns keep their typed city and province until they are
 * matched or edited. The typed columns stay and are filled from the names.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('region_code', 10)->nullable()->after('province');
            $table->string('province_code', 10)->nullable()->after('region_code');
            $table->string('city_code', 10)->nullable()->after('province_code');

            $table->foreign('region_code')->references('code')->on('regions')->nullOnDelete();
            $table->foreign('province_code')->references('code')->on('provinces')->nullOnDelete();
            $table->foreign('city_code')->references('code')->on('cities')->nullOnDelete();
            $table->index(['region_code', 'city_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropForeign(['region_code']);
            $table->dropForeign(['province_code']);
            $table->dropForeign(['city_code']);
            $table->dropIndex(['region_code', 'city_code']);
            $table->dropColumn(['region_code', 'province_code', 'city_code']);
        });
    }
};
