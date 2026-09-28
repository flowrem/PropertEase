<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Regions, provinces and cities/municipalities from the Philippine Standard
 * Geographic Code, keyed by their 10-digit PSGC codes. A city's province is
 * the one it lies in; it is null for Metro Manila's cities and the few
 * places under no province (Isabela City, the BARMM Special Geographic Area).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('regions', function (Blueprint $table) {
            $table->string('code', 10)->primary();
            $table->string('name');
        });

        Schema::create('provinces', function (Blueprint $table) {
            $table->string('code', 10)->primary();
            $table->string('name');
            $table->string('region_code', 10)->index();
            $table->foreign('region_code')->references('code')->on('regions')->cascadeOnDelete();
        });

        Schema::create('cities', function (Blueprint $table) {
            $table->string('code', 10)->primary();
            $table->string('name');
            $table->string('province_code', 10)->nullable()->index();
            $table->string('region_code', 10)->index();
            $table->boolean('is_city')->default(false);
            $table->foreign('province_code')->references('code')->on('provinces')->nullOnDelete();
            $table->foreign('region_code')->references('code')->on('regions')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cities');
        Schema::dropIfExists('provinces');
        Schema::dropIfExists('regions');
    }
};
